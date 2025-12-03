<?php

declare(strict_types=1);

namespace Magentix\MessageQueue\Console\Command;

use Exception;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\MysqlMq\Model\QueueManagement;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\MessageEncoder;
use Magento\Framework\Communication\ConfigInterface as CommunicationConfig;
use Magento\Framework\ObjectManagerInterface;

class Process extends Command
{
    private const OPTION_MESSAGE_ID = 'id';

    private const OPTION_TOPIC = 'topic';

    private const AREA_CODE = 'area';

    private const OPTION_FORCE = 'force';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MessageEncoder $messageEncoder,
        private readonly CommunicationConfig $communicationConfig,
        private readonly ObjectManagerInterface $objectManager,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('queue:message:process')
            ->setDescription('Execute queue messages by topic or identifier')
            ->addOption(
                self::OPTION_TOPIC,
                't',
                InputOption::VALUE_OPTIONAL,
                'Topic name (ie: product_alert)',
            )
            ->addOption(
                self::OPTION_MESSAGE_ID,
                'm',
                InputOption::VALUE_OPTIONAL,
                'Queue message ID',
            )
            ->addOption(
                self::AREA_CODE,
                'a',
                InputOption::VALUE_OPTIONAL,
                'Specify the preferred area: global, frontend, adminhtml',
                Area::AREA_GLOBAL
            )
            ->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_OPTIONAL,
                'Force the message while ignoring the status',
                0
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $areaCode = $input->getOption(self::AREA_CODE);
        if (!in_array($areaCode, [Area::AREA_GLOBAL, Area::AREA_ADMINHTML, Area::AREA_FRONTEND])) {
            $areaCode = Area::AREA_GLOBAL;
        }

        try {
            $this->appState->setAreaCode($areaCode);
        } catch (Exception) {}

        $messageId = $input->getOption(self::OPTION_MESSAGE_ID);
        $topicName = $input->getOption(self::OPTION_TOPIC);
        $force = $input->getOption(self::OPTION_FORCE);

        $messages = $this->getMessages($messageId, $topicName);

        foreach ($messages as $message) {
            if (!$force && !$this->isMessageNew((int)$message['id'])) {
                continue;
            }

            try {
                $body = $this->messageEncoder->decode($message['topic_name'], $message['body']);
            } catch (Exception $exception) {
                $output->writeln($exception->getMessage());
                continue;
            }

            $handlers = $this->getHandlersForTopic($message['topic_name']);

            if (!$handlers) {
                $output->writeln((string)__('No handler found for %1', $message['topic_name']));
                continue;
            }

            foreach ($handlers as $handler) {
                if (!isset($handler['type'], $handler['method'])) {
                    continue;
                }

                $output->writeln((string)__('Message %1: %2::%3', $message['id'], $handler['type'], $handler['method']));

                $object = $this->objectManager->get($handler['type']);

                call_user_func([$object, $handler['method']], $body);

                $this->completeMessage((int)$message['id']);
            }
        }

        return Command::SUCCESS;
    }

    private function getHandlersForTopic(string $topicName): ?array
    {
        try {
            $topicConfig = $this->communicationConfig->getTopic($topicName);
            if (!$topicConfig || !isset($topicConfig['handlers'])) {
                return null;
            }

            $handlers = $topicConfig['handlers'];
            if (empty($handlers)) {
                return null;
            }

            return $handlers;
        } catch (Exception) {
            return null;
        }
    }

    private function getMessages(?string $messageId, ?string $topicName): array
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()->from($this->resourceConnection->getTableName('queue_message'));

        if ($messageId) {
            $select->where('id = ?', $messageId);
        }
        if ($topicName) {
            $select->where('topic_name = ?', $topicName);
        }

        return $connection->fetchAll($select);
    }

    private function isMessageNew(int $messageId): bool
    {
        $connection = $this->resourceConnection->getConnection();

        return (bool)$connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('queue_message_status'), ['status'])
                ->where('message_id = ?', $messageId)
                ->where('status = ?', QueueManagement::MESSAGE_STATUS_NEW)
        );
    }

    private function completeMessage(int $messageId): void
    {
        $connection = $this->resourceConnection->getConnection();

        $connection->update(
            $this->resourceConnection->getTableName('queue_message_status'),
            ['status' => QueueManagement::MESSAGE_STATUS_COMPLETE, 'updated_at' => date('Y-m-d H:i:s')],
            ['message_id = ?' => $messageId]
        );
    }
}

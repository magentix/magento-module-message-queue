<?php

declare(strict_types=1);

namespace Magentix\MessageQueue\Console\Command;

use Magento\MysqlMq\Model\QueueManagement;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\ResourceConnection;

class Status extends Command
{
    private const OPTION_MESSAGE_ID = 'id';

    private const OPTION_TOPIC = 'topic';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('queue:message:status')
            ->setDescription('Shows status of queue messages')
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['ID', 'Topic', 'Status', 'Update On']);

        $messageId = $input->getOption(self::OPTION_MESSAGE_ID);
        $topicName = $input->getOption(self::OPTION_TOPIC);

        $messages = $this->getMessages($messageId, $topicName);

        foreach ($messages as $key => $message) {
            $messages[$key]['status'] = $this->getStatus((int)$message['status']);
        }

        $table->addRows($messages);
        $table->render();

        return Command::SUCCESS;
    }

    private function getMessages(?string $messageId, ?string $topicName): array
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                ['qm' => $this->resourceConnection->getTableName('queue_message')],
                ['qm.id', 'qm.topic_name', 'qms.status', 'qms.updated_at']
            )
            ->joinLeft(
                ['qms' => $this->resourceConnection->getTableName('queue_message_status')],
                'qms.message_id = qm.id',
                []
            );

        if ($messageId) {
            $select->where('qm.id = ?', $messageId);
        }
        if ($topicName) {
            $select->where('qm.topic_name = ?', $topicName);
        }

        return $connection->fetchAll($select);
    }

    private function getStatus(int $status): string
    {
        $statuses = [
            QueueManagement::MESSAGE_STATUS_NEW => 'New',
            QueueManagement::MESSAGE_STATUS_IN_PROGRESS => 'In progress',
            QueueManagement::MESSAGE_STATUS_COMPLETE => 'Completed',
            QueueManagement::MESSAGE_STATUS_RETRY_REQUIRED => 'Retry required',
            QueueManagement::MESSAGE_STATUS_ERROR => 'Error',
            QueueManagement::MESSAGE_STATUS_TO_BE_DELETED => 'To be deleted',
        ];

        return $statuses[$status] ?? '?';
    }
}

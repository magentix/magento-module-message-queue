# Magentix Message Queue Module

A Magento 2 module that allows you to manually execute MySQL message queue (MysqlMq) messages by their identifier or topic.

## Installation

```bash
composer require magentix/magento-module-message-queue
```

## Description

This module adds a CLI command `queue:message:process` that allows you to execute one or more queue messages without having to run the full consumer. This is particularly useful for:

- Executing the handler associated with a topic without running the entire queue
- Debugging a specific message that is causing issues
- Replaying an already processed message (using the **--force** option)

**Important:** This module only works with the MySQL (MysqlMq) backend. It is not compatible with RabbitMQ.

The `queue:message:status` command displays the status of messages in MysqlMq.

## Scenarios

Display **sales_rule.codegenerator** topic statuses:

```bash
bin/magento queue:message:process --topic=sales_rule.codegenerator
+----+--------------------------+-----------+---------------------+
| ID | Topic                    | Status    | Update On           |
+----+--------------------------+-----------+---------------------+
| 4  | sales_rule.codegenerator | New       | 2025-12-01 18:23:52 |
| 5  | sales_rule.codegenerator | New       | 2025-12-02 22:10:54 |
| 9  | sales_rule.codegenerator | New       | 2025-12-03 16:05:28 |
+----+--------------------------+-----------+---------------------+
```

Running all new **sales_rule.codegenerator** topics:

```bash
bin/magento queue:message:process --topic=sales_rule.codegenerator
Message 4: Magento\SalesRule\Model\Coupon\Consumer::process
Message 5: Magento\SalesRule\Model\Coupon\Consumer::process
Message 9: Magento\SalesRule\Model\Coupon\Consumer::process
```

Running a specific topic already executed, nothing happens:

```bash
bin/magento queue:message:process --id=4
```

Running a specific topic already executed using the **--force** option:

```bash
bin/magento queue:message:process --id=4 --force=1
Message 4: Magento\SalesRule\Model\Coupon\Consumer::process
```

## Usage

### Syntax

```bash
bin/magento queue:message:status [options]
```

```bash
bin/magento queue:message:process [options]
```

### Options

| Option    | Shortcut | Description                                                              |
|-----------|----------|--------------------------------------------------------------------------|
| `--id`    | `-m`     | Message ID in the `queue_message` table                                  |
| `--topic` | `-t`     | Topic name (e.g., `product_alert`, `sales.rule.quote.trigger.recollect`) |
| `--area`  | `-a`     | Area code (`global`, `adminhtml`, `frontend`)                            |
| `--force` | `-f`     | Force execution even if the message status is not NEW                    |

### Examples

#### Display all messages

```bash
bin/magento queue:message:status
```

#### Filter by topic

```bash
bin/magento queue:message:status --topic=product_alert
```

#### Display a specific message

```bash
bin/magento queue:message:status --id=123
```

#### Execute a specific message by its ID

```bash
bin/magento queue:message:process --id=123
```

#### Execute all messages for a topic

```bash
bin/magento queue:message:process --topic=product_alert
```

#### Execute a specific message for a topic

```bash
bin/magento queue:message:process --id=456 --topic=product_alert
```

#### Force execution of an already completed or processed message

```bash
bin/magento queue:message:process --id=789 --force=1
```

## Technical Notes

### Multiple Handlers

If a topic has multiple handlers configured in `communication.xml`, **all handlers will be executed** for each message.

### Error Handling

If an error occurs during message decoding, an error message is displayed and processing moves to the next message. The message status is not modified in case of error.

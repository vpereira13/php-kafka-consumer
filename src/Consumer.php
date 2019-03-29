<?php

namespace Kafka\Consumer;

use Kafka\Consumer\Entities\Config;
use Kafka\Consumer\Exceptions\KafkaConsumerException;

class Consumer
{
    private $config;
    private $commits;
    private $consumer;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function consume(): void
    {
        $this->consumer = new \RdKafka\KafkaConsumer($this->setConf());
        $this->consumer->subscribe([$this->config->getTopic()]);

        $this->commits = 0;
        while (true) {
            $message = $this->consumer->consume(500);
            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    $this->executeMessage($message);
                    break;
                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    // NO MESSAGE
                    break;
                default:
                    // ERROR
                    throw new KafkaConsumerException($message->errstr());
                    break;
            }
        }
    }

    private function setConf(): \RdKafka\Conf
    {
        $conf = new \RdKafka\Conf();
        $conf->set('enable.auto.commit', 'false');
        $conf->set('group.id', $this->config->getGroupId());
        $conf->set('bootstrap.servers', $this->config->getBroker());
        $conf->set('security.protocol', $this->config->getSecurityProtocol());
        if ($this->config->isPlainText()) {
            $conf->set('sasl.username', $this->config->getSasl()->getUsername());
            $conf->set('sasl.password', $this->config->getSasl()->getPassword());
            $conf->set('sasl.mechanisms', $this->config->getSasl()->getMechanisms());
        }

        return $conf;
    }

    private function executeMessage(\RdKafka\Message $message): void
    {
        $attempts = 1;
        $success = false;
        do {
            try {
                $consumer = $this->config->getConsumer();
                (new $consumer($message->payload))->handle();
                $success = true;
                $this->commit();
            } catch (\Throwable $exception) {
                if (
                    $this->config->getMaxAttempts()->hasMaxAttempts() &&
                    $this->config->getMaxAttempts()->hasReachedMaxAttempts($attempts)
                ) {
                    $success = true;
                    $this->commit();
                }
                $attempts++;
            }
        } while (!$success);
    }

    private function commit(): void
    {
        $this->commits++;
        if ($this->commits >= $this->config->getCommit()){
            $this->consumer->commit();
            $this->commits = 0;
            return;
        }
    }
}

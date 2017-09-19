<?php
/**
 * Twitter Sentiment
 */
namespace Cilex\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
//use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\TableCell;

use Cilex\Provider\Console\Command;
use Cilex\Service\Twitter;
use Cilex\Service\Sentiment;
use Cilex\Event\EventManager;

use ProgressBar\Manager;
use Colors\Color;

class ConnectCommand extends Command
{

    protected function configure()
    {
        $this->setName('connect')
            ->setDescription('Run Twitter Sentiment Application')
            ->addArgument('words', InputArgument::OPTIONAL, 'Words to track.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $app = $this->getApplication()->getService('console');
        
        $container = $this->getContainer();
        
        $c = new Color();

        $argument = $input->getArgument('words');

        $words = ($argument) ? explode('-', $argument) : [];
        
        //$this->initProgressBar();
        
        $config = $container['application.config'];

        if (sizeof($words)) {
            $config['track'] = $words;
        }
        
        $this->out($c('Name: ' . $app->getName())->blue()->bold()->highlight('white') . PHP_EOL . PHP_EOL);
        $this->out($c('Version: ' . $app->getVersion())->blue()->bold()->highlight('white') . PHP_EOL . PHP_EOL);

        $sentiment = new Sentiment();
        
        EventManager::getInstance()->attach('TweetStream', function ($e) use ($output, $sentiment, $c) {
            $data = $e->getParams();

            $name = '@' . $data['user']['screen_name'];
            $tweet = urldecode(substr($data['text'], 0, 120));
            
            $result = $sentiment->analyze(urldecode($data['text']));
            
            $score = $result['score'];

            $grade = implode(', ', array_values($result['match']));
            $words = implode(', ', array_keys($result['match']));
            
            if (!$grade || !$words) {
                return;
            }

            $table = new Table($output);
            
            $table->setHeaders(array(
                array(new TableCell(urldecode($c($name)->green()->bold()), array('colspan' => 3))),
                //array('Property', 'Value'),
            ));
            
            $table->setRows(array(
                [
                    'Score:',
                    $result['score']
                ],
                new TableSeparator(),
                [
                    'Grade:',
                    $grade
                ],
                new TableSeparator(),
                [
                    'Words:',
                    $words
                ],
                new TableSeparator(),
                array(new TableCell(urldecode($c($tweet)->white()->bold()), array('colspan' => 3))),
            ));
            
            $table->setStyle('compact');
            
            $table->setStyle('borderless');

            $table->render();
            
            $output->writeln(PHP_EOL . PHP_EOL);

        });
        
        $this->initStream($config);
    }

    protected function initStream($config)
    {
        $stream = new Twitter($config['oauth']['ACCESS_TOKEN'], $config['oauth']['ACCESS_SECRET'], $config['oauth']['CONSUMER_KEY'], $config['oauth']['CONSUMER_SECRET']);
        
        // $stream->setMethod(Twitter::METHOD_SAMPLE);
        $stream->setMethod(Twitter::METHOD_FILTER);
        // $stream->setFormat(Twitter::FORMAT_JSON);
        $stream->setTrack($config['track']);
        // $stream->setLang('en');

        $stream->consume();
    }

    protected function initProgressBar()
    {
        $progressBar = new Manager(0, 10);
        
        for ($i = 0; $i <= 10; $i ++) {
            $progressBar->update($i);
            sleep(1);
        }
    }

    protected function out($string)
    {
        echo $string;
    }

    /**
     * Basic log function.
     *
     * @param string $messages            
     * @param String $level
     *            'error', 'info', 'notice'.
     */
    protected function errorLog($message, $level = 'error')
    {
        @error_log(__CLASS__ . ': ' . $message, 0);
    }
}

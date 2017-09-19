<?php

namespace Cilex\Service;

class Sentiment
{
    public function __construct()
    {
        $this->dictionary = $this->generateDictionary();
    }

    protected function prepareSentence($text)
    {
        $rules = [
            'url' => [ // example: http://google.com
                'regex' => "@(https?://([-\w\.]+[-\w])+(:\d+)?(/([\w/_\.#-]*(\?\S+)?[^\.\s])?).*$)@",
                'sub' => ""
            ],
            'hashtag' => [ // example: #maga
                'regex' => "/#(\\w+)/",
                'sub' => ""
            ],
            'reference' => [ // example @potus @flotus
                'regex' => "/@([a-zA-Z0-9_]+)/",
                'sub' => ""
            ],
            'quotes' => [
                'regex' => '/^(\'(.*)\'|"(.*)")$/',
                'sub' => "$2$3"
            ]
        ];
        
        foreach ($rules as $key => $rule) {
            if ($rule['regex']) {
                $text = preg_replace($rule['regex'], $rule['sub'], $text);
            }
        }
        
        return trim($text);
    }

    public function analyze($tweet)
    {
        $dictionary = $this->dictionary;
        
        $sentence = $this->prepareSentence(trim(strtolower($tweet)));
        
        $words = preg_split('/[?!., ]/', $sentence);
        
        $words = array_filter($words, 'strlen');
        
        $total = 0;
        
        $match = [];
        foreach ($words as $word) {
            if (isset($dictionary[$word])) {
                $total += $dictionary[$word];
                $match[$word] = $dictionary[$word];
            }
        }
        
        return [
            'score' => $total,
            'sentence' => $sentence,
            'match' => $match
        ];
    }

    protected function generateDictionary()
    {
        $directory = APPLICATION_PATH . DS . 'dict' . DS;
        
        $dictionaries = [
            ':afinn_emo' => "AFINN-111-emo.txt",
            ':afinn' => "AFINN-111.txt"
        ];
        
        $result = [];
        foreach ($dictionaries as $key => $value) {
            $data = file($directory . $value);
            foreach ($data as $line) {
                list ($word, $value) = explode("\t", $line);
                $result[$word] = trim($value);
            }
        }
        
        return $result;
    }
}

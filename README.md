Cilex Twitter Sentiment
================================================

Cilex Twitter Sentiment is a rudimentary Twitter stream sentiment analysis command line tool. 

## Installation

 1. `git clone` _this_ repository.
 2. Download composer: `curl -s https://getcomposer.org/installer | php`
 3. Install Dependencies': `php composer.phar install`
 4. Rename config/settings.php.inc to settings.php and fill in in your Twitter applications key and token.

<!--
## More Information
-->

## Usage

 - Run the commands in `src/Cilex/Command/`
 - Assuming you have set a track word in the application config simply run the connect command.
 - Track words can be dynamilcally added as parameters separated by a dash. 
```sh
./bin/run.php connect
./bin/run.php connect keyword-keyword-keyword
```

## Application Structure
------------------------

- `bin/` application initialzation point. 

- `src/` is where the bulk of the code lies. 

     - `Cilex/Command`: main connect command minus service libs.

     - `Cilex/Event`: a generic event dispatcher.

     - `Cilex/Provider`: included Cilex runtime helper objects.

     - `Cilex/Service`: included is modified version of Phirehose, a class that makes it easy to consume the Twitter Streaming API. Twitter Sentiment class that parses data from tweets.

`dict/` contains [AFINN: Word list for sentiment analysis](https://finnaarupnielsen.wordpress.com/2011/03/16/afinn-a-new-word-list-for-sentiment-analysis/) dictionary.

## Awesome Libraries Used
-------------------------

+ [cilex/cilex](http://cilex.github.com) - The PHP micro-framework for Command line tools based on the Symfony2 Components.
+ [kevinlebrun/colors](https://github.com/kevinlebrun/colors.php) - Adds colors to cli output.
+ [guiguiboy/php-cli-progress-bar](https://github.com/guiguiboy/PHP-CLI-Progress-Bar) - A PHP5 CLI Progress bar.
+ [fennb/phirehose](https://github.com/fennb/phirehose) - PHP interface to Twitter Streaming API.

## Further Reading
------------------

[Symfony Console Beyond the Basics – Helpers and Other Tools](https://www.sitepoint.com/symfony-console-beyond-the-basics-helpers-and-other-tools)

[AFINN: Word list for sentiment analysis](https://finnaarupnielsen.wordpress.com/2011/03/16/afinn-a-new-word-list-for-sentiment-analysis/https://finnaarupnielsen.wordpress.com/2011/03/16/afinn-a-new-word-list-for-sentiment-analysis/)

[Twitter Mood](http://www.ccs.neu.edu/home/amislove/twittermood/)

[ANEW Sentiment-Weighted Word Bank](http://csea.phhp.ufl.edu/media/anewmessage.html)

[Measuring User Influence in Twitter](https://www.researchgate.net/publication/221298004_Measuring_User_Influence_in_Twitter_The_Million_Follower_Fallacy)

[Sentiment strength detection in short informal text](http://onlinelibrary.wiley.com/doi/10.1002/asi.21416/abstract)

[Twitter as a Corpus for Sentiment Analysis and Opinion Mining](http://www.lrec-conf.org/proceedings/lrec2010/pdf/385_Paper.pdf)

[We Feel Fine](http://wefeelfine.org/faq.html)

[Modeling Statistical Properties of Written Text](http://journals.plos.org/plosone/article?id=10.1371/journal.pone.0005372)

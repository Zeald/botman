<?php

namespace BotMan\BotMan\Messages\Conversations;

use Closure;
use BotMan\BotMan\BotMan;
use Spatie\Macroable\Macroable;
use Illuminate\Support\Collection;
use BotMan\BotMan\Interfaces\ShouldQueue;
use BotMan\BotMan\Messages\Attachments\Audio;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Attachments\Video;
use BotMan\BotMan\Messages\Outgoing\Question;
use BotMan\BotMan\Messages\Attachments\Location;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;

/**
 * Class Conversation.
 */
abstract class Conversation
{
    use Macroable;

    /**
     * @var BotMan
     */
    protected $bot;

    /**
     * @var string
     */
    protected $token;

    /**
     * Number of minutes this specific conversation should be cached.
     * @var int
     */
    protected $cacheTime;

    /**
     * @param BotMan $bot
     */
    public function setBot(BotMan $bot)
    {
        $this->bot = $bot;
    }

    /**
     * @return BotMan
     */
    public function getBot()
    {
        return $this->bot;
    }

    /**
     * @param string|Question $question
     * @param array|Closure|null $next optional closure to process response
     * @param array $additionalParameters
     * @return $this|BotMan\BotMan\Messages\Conversations\QuestionPromise
     */
    public function ask($question, $next = null, $additionalParameters = [])
    {
        $this->bot->reply($question, $additionalParameters);

        // if a next callback is passed, store that & use it to process the response
        if (!is_null($next)) {
            $this->bot->storeConversation($this, $next, $question, $additionalParameters);
            return $this;
        }

        // create a promise, that will resolve in the callback
        return (new QuestionPromise())
            ->for($this)
            ->question($question, $additionalParameters);
    }

    /**
     * @param string|\BotMan\BotMan\Messages\Outgoing\Question $question
     * @param array|Closure $next
     * @param array|Closure $repeat
     * @param array $additionalParameters
     * @return $this
     */
    public function askForImages($question, $next = null, $repeat = null, $additionalParameters = [])
    {
        $additionalParameters['__getter'] = 'getImages';
        $additionalParameters['__pattern'] = Image::PATTERN;
        $additionalParameters['__repeat'] = ! is_null($repeat) ? $this->bot->serializeClosure($repeat) : $repeat;

        return $this->ask($question, $next, $additionalParameters);
    }

    /**
     * @param string|\BotMan\BotMan\Messages\Outgoing\Question $question
     * @param array|Closure $next
     * @param array|Closure $repeat
     * @param array $additionalParameters
     * @return $this
     */
    public function askForVideos($question, $next = null, $repeat = null, $additionalParameters = [])
    {
        $additionalParameters['__getter'] = 'getVideos';
        $additionalParameters['__pattern'] = Video::PATTERN;
        $additionalParameters['__repeat'] = ! is_null($repeat) ? $this->bot->serializeClosure($repeat) : $repeat;

        return $this->ask($question, $next, $additionalParameters);
    }

    /**
     * @param string|\BotMan\BotMan\Messages\Outgoing\Question $question
     * @param array|Closure $next
     * @param array|Closure $repeat
     * @param array $additionalParameters
     * @return $this
     */
    public function askForAudio($question, $next = null, $repeat = null, $additionalParameters = [])
    {
        $additionalParameters['__getter'] = 'getAudio';
        $additionalParameters['__pattern'] = Audio::PATTERN;
        $additionalParameters['__repeat'] = ! is_null($repeat) ? $this->bot->serializeClosure($repeat) : $repeat;

        return $this->ask($question, $next, $additionalParameters);
    }

    /**
     * @param string|\BotMan\BotMan\Messages\Outgoing\Question $question
     * @param array|Closure $next
     * @param array|Closure $repeat
     * @param array $additionalParameters
     * @return $this
     */
    public function askForLocation($question, $next = null, $repeat = null, $additionalParameters = [])
    {
        $additionalParameters['__getter'] = 'getLocation';
        $additionalParameters['__pattern'] = Location::PATTERN;
        $additionalParameters['__repeat'] = ! is_null($repeat) ? $this->bot->serializeClosure($repeat) : $repeat;

        return $this->ask($question, $next, $additionalParameters);
    }

    /**
     * Repeat the previously asked question.
     * @param string|Question $question
     */
    public function repeat($question = '')
    {
        $conversation = $this->bot->getStoredConversation();

        if (! $question instanceof Question && ! $question) {
            $question = unserialize($conversation['question']);
        }

        $next = $conversation['next'];
        $additionalParameters = unserialize($conversation['additionalParameters']);

        if (is_string($next)) {
            $next = unserialize($next)->getClosure();
        } elseif (is_array($next)) {
            $next = Collection::make($next)->map(function ($callback) {
                if ($this->bot->getDriver()->serializesCallbacks() && ! $this->bot->runsOnSocket()) {
                    $callback['callback'] = unserialize($callback['callback'])->getClosure();
                }

                return $callback;
            })->toArray();
        }
        $this->ask($question, $next, $additionalParameters);
    }

    /**
     * @param string|\BotMan\BotMan\Messages\Outgoing\Question $message
     * @param array $additionalParameters
     * @return $this
     */
    public function say($message, $additionalParameters = [])
    {
        $this->bot->reply($message, $additionalParameters);

        return $this;
    }

    /**
     * Repeatedly ask for valid communication until we receive it. If the
     * validation fails, an updated question will be asked, suggestions and/or
     * handover offered (based on the returned InvalidAnswer instance). After 2
     * attempts we will offer to hand over to a real person.
     *
     * @param mixed|BotMan\BotMan\Messages\Incoming\Answer $answer the
     *     response we are validating
     * @param Closure $validation a closure that receives the response, and validates it
     *     returning either the valid response, or an instance of InvalidAnswer
     * @param mixed $suggestion optional suggestion that has been made to the
     *     user (meaning we will accept a yes-like response)
     * @param integer $attempts how many times we've re-asked for valid input
     * @return string|QuestionPromise the validated response, or a new
     *     QuestionPromise, re-asking the question, offering suggestions and/or
     *     offering handover
     */
    public function validate($answer, $validation, $suggestion = null, $attempts = 1)
    {
        // if we've received a handover request abort validation
        if ($this->handoverRequest($answer)) {
            return;
        }

        // check if we have a valid response
        $result = $validation($answer);
        if ($result instanceof InvalidAnswer) {

            // if a suggestion was made - check for a 'yes-like' response.
            if (!is_null($suggestion) && $this->isYes($answer)) {
                return $suggestion;
            }

            // retrieve the new question
            $question = $result->question();

            // @todo - config setting for name of handover conversation. Only offer
            // a handover if that conversation is set.
            // @todo - global config setting # of failures before offering handover
            // @todo - InvalidAnswer->offerHandover();
            if ($attempts > 2) {
                $question = $this->offerHandover($question);
            }

            // ask the new question, & validate its response passing through
            // the current suggestion in case they say 'Yes'.
            return $this->ask($question)
                ->attempt($attempts + 1)
                ->suggested($result->getSuggestion());
        } else {
            return $result;
        }
    }

    /**
     * Determine if an answer is a request for a handover to a real person. If
     * it is, launch the handover conversation
     *
     * @param BotMan\BotMan\Messages\Incoming\Answer $answer
     * @return boolean
     */
    protected function handoverRequest($answer)
    {
        if ($answer->getValue() === 'handover:request') {
            // $this->bot->startConversation(new Handover($this)); // @todo set this in config var & resolve it
            return true;
        }

        return false;
    }

    /**
     * Offer to handover to a real person. If a question is specified, tack
     * the offer onto the end of the existing question
     *
     * @param string|mixed $question optional question to add the
     *     offer onto. Supports string, buttonset or button
     * @return \App\Extensions\Elements\Buttonset
     */
    protected function offerHandover($question = null)
    {
        return $question;
        // @TODO - need rich UI elements to implement this and set text in config
        // $offer = $this->createButtonset($question);

        // // add a handover button to the question
        // $offer->text($offer->getText() . "\n\n" . trans('shop.handover.offer'))
        //     ->addButton(
        //         Button::create(trans('shop.handover.button'), 'handover:request'),
        //     );

        // return $offer;
    }

    /**
     * Create a buttonset. Optionally receives an element to create the buttonset
     * from. Supports receving Buttonset, Button, and a primary data type
     * (string, integer etc)
     *
     * @param mixed $convert element (or string etc) to convert to a buttonset
     * @return \App\Extensions\Elements\Buttonset
     */
    protected function createButtonset($convert = null)
    {
        // @TODO
        // $buttonset = new Buttonset();

        // // transform the existing question into a buttonset
        // if (!is_null($convert)) {
        //     if ($convert instanceof Buttonset) {
        //         $buttonset = $convert;
        //     } elseif ($convert instanceof Button) {
        //         $buttonset->addButton($convert);
        //     } elseif (!is_object($convert)) {
        //         $buttonset->text($convert);
        //     } else {
        //         throw new Exception('Cannot create a Buttonset from a '
        //             . get_class($convert) . ' element');
        //     }
        // }

        // return $buttonset;
    }

    /**
     * Determine if an answer is something we can take as a 'yes'
     *
     * @param BotMan\BotMan\Messages\Incoming\Answer|mixed $answer
     * @return boolean
     */
    protected function isYes($answer) {
        if ($answer instanceof Answer) {
            $answer = $answer->getValue();
        }

        // grab the first word if more than one
        $answer = collect(preg_split('/[\s]+/', $answer))
            ->filter()
            ->shift();

        // accept boolean true
        if ($answer === true) {
            return true;
        }

        // otherwise require a string answer that looks like yes
        if (!is_string($answer)) {
            return false;
        }

        // @todo - allow yes-es to be set in config
        $answer = strtolower($answer);
        if (collect(['yes', 'y', 'yep', 'yup', 'ok'])->contains($answer)) {
            return true;
        }

        return false;
    }


    /**
     * Should the conversation be skipped (temporarily).
     * @param  IncomingMessage $message
     * @return bool
     */
    public function skipsConversation(IncomingMessage $message)
    {
        //
    }

    /**
     * Should the conversation be removed and stopped (permanently).
     * @param  IncomingMessage $message
     * @return bool
     */
    public function stopsConversation(IncomingMessage $message)
    {
        //
    }

    /**
     * Override default conversation cache time (only for this conversation).
     * @return mixed
     */
    public function getConversationCacheTime()
    {
        return $this->cacheTime ?? null;
    }

    /**
     * @return mixed
     */
    abstract public function run();

    /**
     * @return array
     */
    public function __sleep()
    {
        $properties = get_object_vars($this);
        if (! $this instanceof ShouldQueue) {
            unset($properties['bot']);
        }

        return array_keys($properties);
    }
}

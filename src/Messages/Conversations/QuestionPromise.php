<?php

namespace BotMan\BotMan\Messages\Conversations;

use Exception;
use Illuminate\Support\Collection;

/**
 * Class QuestionPromise.
 *
 * Implements a basic promise pattern with functionality focused on a bot
 * conversation. Allows you to specify one or more callbacks with `then`. You
 * can also specify validation which will recurse until it passes or the user
 * bails out into a handover conversation.
 *
 * Usage:
 * $this->ask('What\'s the magic number?')
 *     ->validate(function ($answer) {
 *         if ($answer instanceof Answer) {
 *             $answer = $answer->getValue();
 *         }
 *
 *         if ((int) $answer != 21) {
 *             return (new InvalidAnswer())
 *                 ->ask('Sorry you got it wrong, did you mean 21?')
 *                 ->suggest('Um, yeah for sure. 21', 21);
 *         }
 *         return $answer;
 *     })
 *     ->then(function ($answer) {
 *         $this->say('You got it! It was ' . $answer);
 *         return $answer + 1;
 *     })
 *     ->then(function ($answer) {
 *         $this->say('Now it is ' . $answer);
 *     });
 *
 * Limitations: this is not a full promise implementation. It is designed to be
 * simple for efficients & also to minimise the size of each serialized instance
 * that has to be stored. For example, missing feature include the ability to
 * return a promise from a `then` closure, or to specify rejection callbacks.
 */
class QuestionPromise
{
    /**
     * A reference to the conversation this question promise relates to
     *
     * @var \App\Extensions\Conversation $conversation
     */
    protected $conversation;

    /**
     * An collection of response processing callbacks that are queued for
     * execution
     *
     * @var \Illuminate\Support\Collection
     */
    protected $queue;

    /**
     * A validation callback that must pass before the queue will execute
     *
     * @var \Illuminate\Support\Collection
     */
    protected $validation;

    /**
     * What attempt # is this to pass the validation?
     *
     * @var integer
     */
    protected $attempt = 1;

    /**
     * What was suggested to the user as a valid response after a validation
     * fail?
     *
     * @var string
     */
    protected $suggested;

    /**
     * The question being asked that this promise relates to
     *
     * @param string|mixed|Question $question
     */
    protected $question;

    /**
     * Additional parameters applying to the question
     * @param array $additionalParameters
     */
    protected $additionalParameters;

    /**
     * Create a new instance, preparing an empty callback queue
     */
    public function __construct()
    {
        $this->queue = new Collection();
    }

    /**
     * Sets the conversation instance this QuestionPromise is for
     *
     * @param \App\Extensions\Conversation $conversation
     * @return \App\Extensions\QuestionPromise
     */
    public function for($conversation)
    {
        $this->conversation = $conversation;

        return $this;
    }

    /**
     * Specify what validation attempt we are up to
     *
     * @param integer $attempt
     * @return \App\Extensions\QuestionPromise
     */
    public function attempt($attempt)
    {
        $this->attempt = $attempt;

        return $this;
    }

    /**
     * Specify what suggestion previous validation attempt made (if any)
     *
     * @param string $suggestion
     * @return \App\Extensions\QuestionPromise
     */
    public function suggested($suggestion)
    {
        $this->suggested = $suggestion;

        return $this;
    }

    /**
     * Sets the question this QuestionPromise is for
     *
     * @param string|mixed|Question $question
     * @param array $additionalParameters
     * @return \App\Extensions\QuestionPromise
     */
    public function question($question, $additionalParameters)
    {
        $this->question = $question;
        $this->additionalParameters = $additionalParameters;

        return $this;
    }

    /**
     * A callback to validate the answer. This will execute before any fulfilled
	 * handlers (that were set with `then` or `receive`).
     *
     * @param Closure $callback validation callback
     * @return GuzzleHttp\Promise\PromiseInterface
     */
    public function validate($callback)
    {
        $this->validation = $callback;

        return $this;
    }

    /**
     * Specifies callback(s) to execute if the promise is resolved
     *
     * @param Closure $callback
     * @return App\Extensions\QuestionPromise
     */
    public function then($callback)
    {
        // queue this callback
        $this->queue->push($callback);

        $this->store();

        return $this;
    }

    /**
     * Resolve the promise by executing any validation, and if that passes,
     * execute any queued callbacks
     *
     * @param BotMan\BotMan\Messages\Incoming\Answer
     * @return mixed|null
     */
    public function resolve($answer)
    {
        if (!$this->conversation) {
            throw new Exception("Unable to resolve a QuestionPromise without a conversation instance");
        }

        // attempt to validate
        $answer = $this->validateAnswer($answer);
        if (is_null($answer) || $answer === false) {
            return;
        }

        // execute the queue of callbacks, passing any returned value to the next
        // if a callback returns null, we will treat it as a break
        return $this->queue->reduce(function ($lastAnswer, $callback) {
            return !is_null($lastAnswer)
                ? $this->executeCallback($callback, $lastAnswer)
                : null;
        }, $answer);
    }

    /**
     * Run an answer through any validation callback. If it fails (an
     * InvalidAnswer instance is returned), reask the question. Returns the
     * validated answer, or null (indicating processing should break)
     *
     * @param BotMan\BotMan\Messages\Incoming\Answer
     * @return mixed|BotMan\BotMan\Messages\Incoming\Answer|null
     */
    public function validateAnswer($answer)
    {
        // accept all if no validation defined
        if (!$this->validation) {
            return $answer;
        }

		// prepare the callback by binding the conversation before validating
		// through the conversation
        $result = $this->conversation->validate(
            $answer,
            $this->validation->bindTo($this->conversation, $this->conversation),
            $this->getSuggested(),
            $this->getAttempt(),
        );

        // if the answer was another question, then we can assume the validation
        // failed
        if ($result instanceof static) {

            // the last answer was invalid, so we have reasked a new question
            // & possibly made a suggestion(s). We need to copy over the new
            // attempt & suggested values into this promise & resave the
            // conversation to await the new answer
            $this->attempt($result->getAttempt())
                ->suggested($result->getSuggested())
                ->store();
        } else {

			// validation passed (at least a new question wasn't asked), so
			// return the result to pass to the queue of callbacks
            return $result;
        }
    }

    /**
     * Store the current question & this promise against the current conversation
     *
     * @return \App\Extensions\QuestionPromise
     */
    protected function store()
    {
        // remove any conversation property for storage & restore after
        $conversation = $this->conversation;
        $this->conversation = null;

        // store the conversation so the callback executes on response
        $conversation->store(
            $this->question,
            $this,
            $this->additionalParameters
        );

        $this->conversation = $conversation;
        return $this;
    }

    /**
     * Execute a callback in the context of the conversation instance (so
     * `this` is available in the closure representing the Conversation)
     *
     * @param Closure $callback
     * @param mixed ...$params one or more parameters to pass to the callback
     * @return mixed result of the callback
     */
    protected function executeCallback($callback, ...$params)
    {
        // bind the current conversation instance to the callback (so it is
        // accessible as `$this` inside the callback)
        $callback = $callback->bindTo($this->conversation, $this->conversation);

        return $callback(...$params);
    }

    /**
     * Retrieve validation attempt number
     *
     * @return integer
     */
    protected function getAttempt()
    {
        return $this->attempt;
    }

    /**
     * Retrieve value suggested by previous validation
     *
     * @return string
     */
    protected function getSuggested()
    {
        return $this->suggested;
    }
}

<?php

namespace BotMan\BotMan\Messages\Conversations;

use BotMan\BotMan\Messages\Outgoing\Question;

/**
 * Class InvalidAnswer.
 *
 * This class is used by validation routines (see
 * App\Extensions\Conversation::validate) to indicate that the received value
 * is invalid. It can:
 * - specify what should be said in an attempt to collect a valid
 *   response
 * - provide the ability to suggest a valid response that the user can click
 *   as a button, or say 'Yes' to.
 */
class InvalidAnswer
{
   /**
     * What should be asked in an attempt to collect a valid response
     *
     * @var string $ask
     */
    protected $ask;

   /**
     * A value that has / should be suggested to the user & should be submitted
     * as a button
     *
     * @var string $suggestionValue
     */
    protected $suggestionValue;

   /**
     * Text to go on the suggestion button
     *
     * @var string $suggestionText
     */
    protected $suggestionText;

    /**
     * Define what should be said in an attempt to collect a valid response
     *
     * @param string $say
     * @return \App\Extensions\InvalidAnswer
     */
    public function ask($ask)
    {
        $this->ask = $ask;

        return $this;
    }

    /**
     * Define what has been / should be suggested as a valid response
     *
     * @param string $text suggestion text for the button
     * @param mixed $value optional value for the button (if different from text)
     * @return \App\Extensions\InvalidAnswer
     */
    public function suggest($text, $value = null)
    {
        $this->suggestionText = $text;
        $this->suggestionValue = $value ?? $text;

        return $this;
    }

    /**
     * Retrieve a suggested value
     *
     * @return mixed
     */
    public function getSuggestion()
    {
        return $this->suggestionValue;
    }

    /**
     * Retrieve the new question we've been told to ask including any suggestion
     *
     * @return string|App\Extensions\Elements\Buttonset
     */
    public function question()
    {
        $question = $this->ask;

        // if anything has been suggested, add a button to the question
        if ($this->suggestionText) {
            $question = (new Buttonset())
                ->text($question)
                ->addButton(
                    new Button($this->suggestionText, $this->suggestionValue)
                );
        }

        return $question;
    }
}

<?php

namespace EmailSmartSender\Services;

use EmailApi\Exceptions;
use EmailApi\Interfaces;
use EmailApi\Basics;

/**
 * Class SmartSender
 * Make and send each mail via SmartSender service
 * @link https://kb.smartsender.io/documentation/api-overview/
 * This service cannot send attachments!
 */
class SmartSender implements Interfaces\Sending
{
    /** @var Interfaces\LocalProcessing */
    protected $localProcess = '';
    /** @var string */
    protected $apiPath = "https://api.sndmart.com/send";
    /** @var string */
    protected $apiDiscardPath = "https://api.sndmart.com/v2/blacklist/remove";
    /** @var string */
    protected $apiKey = '';
    /** @var string */
    protected $apiSecret = '';

    public function __construct(Interfaces\LocalProcessing $localProcess, string $apiKey = '', string $apiSecret = '')
    {
        $this->localProcess = $localProcess;
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    public function systemServiceId(): int
    {
        return 5;
    }

    public function canUseService(): bool
    {
        return (bool)$this->apiKey
            && (bool)$this->apiSecret;
    }

    /**
     * Send mail directly via php - just use classical PHPMailer
     *
     * @param Interfaces\Content $content
     * @param Interfaces\EmailUser $to
     * @param Interfaces\EmailUser $from
     * @param Interfaces\EmailUser $replyTo
     * @param bool $toDisabled
     * @return Basics\Result
     */
    public function sendEmail(Interfaces\Content $content, Interfaces\EmailUser $to, ?Interfaces\EmailUser $from = null, ?Interfaces\EmailUser $replyTo = null, $toDisabled = false): Basics\Result
    {
        if (!empty($content->getAttachments())) {
            return new Basics\Result(false, 'Contains attachments, this is not supported', 0);
        }

        if ($toDisabled) {
            try {
                $this->enableMailOnRemote($to);
                $this->localProcess->enableMailLocally($to);
            } catch (Exceptions\EmailException $ex) {
                return new Basics\Result(false, $ex->getMessage(), 0);
            }
        }

        $data = [
            'key' => $this->apiKey,
            'secret' => $this->apiSecret,
            'message' => [
                'from_email' => $from->getEmail(),
                'from_name' => $from->getEmailName(),
                'subject' => $content->getSubject(),
                'to' => [
                    [
                        'name' => $to->getEmailName(),
                        'email' => $to->getEmail()
                    ]
                ],
                'html' => $content->getHtmlBody(),
                'tags' => [
                    $content->getTag(),
                ],
            ]
        ];
        if (!is_null($replyTo)) {
            $data['message']['reply_to'] = [
                [
                    'name' => $replyTo->getEmailName(),
                    'email' => $replyTo->getEmail()
                ]
            ];
        }

        // magic with unsubscribe
        $unSubscribeLink = $content->getUnsubscribeLink();
        $unSubscribeEmail = $content->getUnsubscribeEmail();
        if (!(empty($unSubscribeLink) || empty($unSubscribeEmail))) {
            if ($unSubscribeLink && $unSubscribeEmail) {
                if ($content->canUnsubscribeOneClick()) {
                    $data['headers']['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
                }
                $data['headers']['List-Unsubscribe'] = '<' . $unSubscribeLink . '>, <' . $unSubscribeEmail . '>';
            } elseif ($unSubscribeLink) {
                if ($content->canUnsubscribeOneClick()) {
                    $data['headers']['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
                }
                $data['headers']['List-Unsubscribe'] = '<' . $unSubscribeLink . '>';
            } else {
                $data['headers']['List-Unsubscribe'] = '<' . $unSubscribeEmail . '>';
            }
        }
        if (!is_null($replyTo)) {
            $data['headers']['Reply-To'] = $replyTo->getEmailName() . ' <' . $replyTo->getEmail() . '>';
        }
        $encoded = json_encode($data);

        $ch = curl_init($this->apiPath);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $ch, CURLOPT_HTTPHEADER,
            [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($encoded)
            ]
        );

        $result = json_decode(curl_exec($ch));
        $resultCode = (isset($result->result)) ? $result->result : 0;
        $resultMessageId = (isset($result->message_id)) ? $result->message_id : 0;
        return new Basics\Result((1 == $resultCode), $result, $resultMessageId);
    }

    /**
     * Remove address from internal bounce log on SmartSender
     * @param Interfaces\EmailUser $to
     * @return void
     * @throws Exceptions\EmailException
     */
    protected function enableMailOnRemote(Interfaces\EmailUser $to): void
    {
        $data = [
            'email' => $to->getEmail(),
        ];
        $encoded = json_encode($data);

        $ch = curl_init($this->apiDiscardPath);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $ch, CURLOPT_HTTPHEADER,
            [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($encoded),
                sprintf('access-token: %s', $this->apiSecret),
            ]
        );

        $result = json_decode(curl_exec($ch));
        $resultCode = (isset($result->result)) ? $result->result : 0;
        $resultCode = boolval($resultCode);
        $resultMessage = (isset($result->errors)) ? $result->errors : ['Unknown error!'];
        if (!$resultCode) {
            throw new Exceptions\EmailException(current($resultMessage));
        }
    }
}

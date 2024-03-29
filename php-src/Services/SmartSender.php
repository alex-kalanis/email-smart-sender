<?php

namespace kalanis\EmailSmartSender\Services;


use kalanis\EmailApi\Exceptions;
use kalanis\EmailApi\Interfaces;
use kalanis\EmailApi\Basics;


/**
 * Class SmartSender
 * Make and send each mail via SmartSender service
 * @link https://kb.smartsender.io/documentation/api-overview/
 * This service cannot send attachments!
 */
class SmartSender implements Interfaces\ISending
{
    protected Interfaces\ILocalProcessing $localProcess;
    protected string $apiPath = "https://api.sndmart.com/send";
    protected string $apiDiscardPath = "https://api.sndmart.com/v2/blacklist/remove";
    protected string $apiKey = '';
    protected string $apiSecret = '';

    public function __construct(Interfaces\ILocalProcessing $localProcess, string $apiKey = '', string $apiSecret = '')
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
        return boolval($this->apiKey)
            && boolval($this->apiSecret);
    }

    /**
     * Send mail directly into the service
     *
     * @param Interfaces\IContent $content
     * @param Interfaces\IEmailUser $to
     * @param Interfaces\IEmailUser $from
     * @param Interfaces\IEmailUser $replyTo
     * @param bool $toDisabled
     * @return Basics\Result
     */
    public function sendEmail(Interfaces\IContent $content, Interfaces\IEmailUser $to, ?Interfaces\IEmailUser $from = null, ?Interfaces\IEmailUser $replyTo = null, $toDisabled = false): Basics\Result
    {
        try {
            if (!empty($content->getAttachments())) {
                throw new Exceptions\EmailException('Contains attachments, this is not supported');
            }

            if ($toDisabled) {
                $this->enableMailOnRemote($to);
                $this->localProcess->enableMailLocally($to);
            }

            $data = [
                'key' => $this->apiKey,
                'secret' => $this->apiSecret,
                'message' => [
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
            if (!is_null($from)) {
                $data['message']['from_email'] = $from->getEmail();
                $data['message']['from_name'] = $from->getEmailName();
            }
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
            if ((!empty($unSubscribeLink)) || (!empty($unSubscribeEmail))) {
                if ((!empty($unSubscribeLink)) && (!empty($unSubscribeEmail))) {
                    if ($content->canUnsubscribeOneClick()) {
                        $data['headers']['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
                    }
                    $data['headers']['List-Unsubscribe'] = '<' . $unSubscribeLink . '>, <' . $unSubscribeEmail . '>';
                } elseif (!empty($unSubscribeLink)) {
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
            if (false === $encoded) {
                throw new Exceptions\EmailException('Cannot encode data');
            }

            $ch = curl_init($this->apiPath);
            if (false === $ch) {
                throw new Exceptions\EmailException('Cannot connect');
            }
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

            $got = curl_exec($ch);
            if (false === $got) {
                throw new Exceptions\EmailException('Cannot understand response');
            }
            if (true === $got) {
                return new Basics\Result(true, 'Message sent');
            }

            $result = (object) json_decode($got);
            $resultCode = (isset($result->result)) ? $result->result : 0;
            $resultMessageId = (isset($result->message_id)) ? $result->message_id : 0;
            return new Basics\Result((1 == $resultCode), $got, strval($resultMessageId));
        } catch (Exceptions\EmailException $ex) {
            return new Basics\Result(false, $ex->getMessage());
        }
    }

    /**
     * Remove address from internal bounce log on SmartSender
     * @param Interfaces\IEmailUser $to
     * @throws Exceptions\EmailException
     * @return void
     */
    protected function enableMailOnRemote(Interfaces\IEmailUser $to): void
    {
        $data = [
            'email' => $to->getEmail(),
        ];
        $encoded = json_encode($data);
        if (false === $encoded) {
            throw new Exceptions\EmailException('Cannot encode data');
        }

        $ch = curl_init($this->apiDiscardPath);
        if (false === $ch) {
            throw new Exceptions\EmailException('Cannot connect');
        }

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

        $got = curl_exec($ch);
        if (false === $got) {
            throw new Exceptions\EmailException('Cannot understand response');
        }
        if (true === $got) {
            return;
        }

        $result = (object) json_decode($got);
        $resultCode = (isset($result->result)) ? $result->result : 0;
        $resultCode = boolval($resultCode);
        $resultMessage = (isset($result->errors)) ? $result->errors : ['Unknown error!'];
        if (!$resultCode) {
            throw new Exceptions\EmailException(current($resultMessage));
        }
    }
}

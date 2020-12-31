# Email Api - Smart Sender

Sending emails - via service SmartSender 

This service cannot send attachments!

Contains libraries for sending emails via SmartSender 

# PHP Installation

```
{
    "require": {
        "alex-kalanis/email-smart-sender": "dev-master"
    }
}
```

(Refer to [Composer Documentation](https://github.com/composer/composer/blob/master/doc/00-intro.md#introduction) if you are not
familiar with composer)


# PHP Usage

1.) Use your autoloader (if not already done via Composer autoloader)

2.) Add selected services into the "\kalanis\EmailApi\LocalInfo\ServicesOrdering" constructor. Beware additional necessary params and classes for your use case.

3.) Add Ordering and your implementation of "\kalanis\EmailApi\Interfaces\ILocalProcessing" into your "\kalanis\EmailApi\Sending".

4.) Just call sending as described in the "\kalanis\EmailApi\Sending".

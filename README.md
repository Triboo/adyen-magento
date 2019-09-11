# Adyen Payment plugin for Magento
Use Adyen's plugin for Magento to offer frictionless payments online, in-app, and in-store.

## Magento 1 end of life (EOL)
Magento has officially announced that Magento 1 will reach end of life (EOL) on June 1, 2020.

[For more information visit our documentation page](https://docs.adyen.com/plugins/magento-1/magento-1-eol)

## Integration
The plugin integrates card component(Secured Fields) using Adyen Checkout for all card payments. Local/redirect payment methods are integrated with DirectoryLookup and HPP. For Point Of Sale (POS) payments we use Terminal API using Cloud-based communication. Boleto, MultiBanco and SEPA are a direct API integration into Adyen.

## Requirements
The plugin supports the Magento Community (version 1.8 and higher) and Enterprise edition (version 1.13 and higher). 
For Magento 2.x please use the following plugin: [https://github.com/Adyen/adyen-magento2](https://github.com/Adyen/adyen-magento2)

## Collaboration
We commit all our new features directly into our GitHub repository.
But you can also request or suggest new features or code changes yourself!


## Installation
Copy the folders to your main Magento environment or use composer:
```
composer require adyen/payment
```

## Documentation
[Magento documentation](https://docs.adyen.com/developers/plug-ins-and-partners/magento-1)

## Videos
* [Point-of-Sale demo of the Adyen Payment module](https://vimeo.com/128983014)

## Setup Cron
Make sure that your Magento cron is running every minute. We are using a cronjob to process the notifications, our webhook service. The cronjob will be executed every minute. It only executes the notifications that have been received at least 5 minutes ago. This is to ensure that Magento has created the order, and all save after events are executed. A handy tool to get insight into your cronjobs is AOE scheduler. You can download this tool through <a target="_blank" href="https://github.com/AOEpeople/Aoe_Scheduler/releases">GitHub</a>.

## Caching / Varnish configuration
In case you are using a caching layer such as Varnish, please exclude the following URL pattern from being cached
```
/adyen/process/*
```
and
```
/adyen/ThreeDS2Process/*
```

## Support
You can create issues on our Magento Repository. In case of specific problems with your account, please contact <a href="mailto:support@adyen.com">support@adyen.com</a>.

## License
MIT license. For more information, see the LICENSE file.

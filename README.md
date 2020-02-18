<p align="center">
    <a href="https://monsieurbiz.com" target="_blank">
        <img src="https://monsieurbiz.com/logo.png" width="250px" />
    </a>
    &nbsp;&nbsp;&nbsp;&nbsp;
    <a href="https://sylius.com" target="_blank">
        <img src="https://demo.sylius.com/assets/shop/img/logo.png" width="200px" />
    </a>
</p>

<h1 align="center">Sales Reports</h1>

[![Sales Reports Plugin license](https://img.shields.io/github/license/monsieurbiz/SyliusSalesReportsPlugin)](https://github.com/monsieurbiz/SyliusSalesReportsPlugin/blob/master/LICENSE.txt)
[![Build Status](https://travis-ci.com/monsieurbiz/SyliusSalesReportsPlugin.svg?branch=master)](https://travis-ci.com/monsieurbiz/SyliusSalesReportsPlugin)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/monsieurbiz/SyliusSalesReportsPlugin/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/monsieurbiz/SyliusSalesReportsPlugin/?branch=master)

A simple plugin to have sales reports in Sylius

![Reports form](screenshots/reports_form.png)

## Installation

```bash
composer require monsieurbiz/sylius-sales-reports-plugin
```

Change your `config/bundles.php` file to add the line for the plugin : 

```php
<?php

return [
    //..
    MonsieurBiz\SyliusSalesReportsPlugin\MonsieurBizSyliusSalesReportsPlugin::class => ['all' => true],
];
```

Then create the config file in `config/packages/monsieur_biz_sales_reports_plugin.yaml` :

```yaml
imports:
  - { resource: "@MonsieurBizSyliusSalesReportsPlugin/Resources/config/config.yaml" }
``` 


Finally import the routes in `config/routes.yaml` : 

```yaml
monsieur_biz_sales_reports_plugin:
    resource: "@MonsieurBizSyliusSalesReportsPlugin/Resources/config/routing.yaml"
```

## Reports

All reports columns are sortable by clicking on it.

### Global sales report

![Global sales report](screenshots/global.png)

### Average sales report

![Average sales report](screenshots/average.png)

### Product report

![Product report](screenshots/product.png)

### Product variant report

![Product variant report](screenshots/product_variant.png)

### Option report

![Option report](screenshots/option.png)

### Option value report

![Option value report](screenshots/option_value.png)

### Add your custom reports !

An event is available to add your custom reports, see `CustomReportEvent` class in the plugin.

## Contributing

You can open an issue or a Pull Request if you want! 😘  
Thank you!

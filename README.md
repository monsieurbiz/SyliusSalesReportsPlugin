[![Banner of Sylus Sales Reports plugin](docs/images/banner.jpg)](https://monsieurbiz.com/agence-web-experte-sylius)

<h1 align="center">Sales Reports</h1>

[![Sales Reports Plugin license](https://img.shields.io/github/license/monsieurbiz/SyliusSalesReportsPlugin?public)](https://github.com/monsieurbiz/SyliusSalesReportsPlugin/blob/master/LICENSE.txt)
[![Tests Status](https://img.shields.io/github/workflow/status/monsieurbiz/SyliusSalesReportsPlugin/Tests?logo=github)](https://github.com/monsieurbiz/SyliusSalesReportsPlugin/actions?query=workflow%3ATests)
[![Security Status](https://img.shields.io/github/workflow/status/monsieurbiz/SyliusSalesReportsPlugin/Security?label=security&logo=github)](https://github.com/monsieurbiz/SyliusSalesReportsPlugin/actions?query=workflow%3ASecurity)

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

Finally import the routes in `config/routes/monsieurbiz_sylius_sales_reports_plugin.yaml` : 

```yaml
monsieurbiz_sales_reports_plugin:
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

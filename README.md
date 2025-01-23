[![Banner of Sylus Sales Reports plugin](docs/images/banner.jpg)](https://monsieurbiz.com/agence-web-experte-sylius)

<h1 align="center">Sylius Sales Reports</h1>

[![Sales Reports Plugin license](https://img.shields.io/github/license/monsieurbiz/SyliusSalesReportsPlugin?public)](https://github.com/monsieurbiz/SyliusSalesReportsPlugin/blob/master/LICENSE.txt)
[![Tests Status](https://img.shields.io/github/actions/workflow/status/monsieurbiz/SyliusSalesReportsPlugin/tests.yaml?branch=master&logo=github)](https://github.com/monsieurbiz/SyliusSalesReportsPlugin/actions?query=workflow%3ATests)
[![Recipe Status](https://img.shields.io/github/actions/workflow/status/monsieurbiz/SyliusSalesReportsPlugin/recipe.yaml?branch=master&label=recipes&logo=github)](https://github.com/monsieurbiz/SyliusSalesReportsPlugin/actions?query=workflow%3ASecurity)
[![Security Status](https://img.shields.io/github/actions/workflow/status/monsieurbiz/SyliusSalesReportsPlugin/security.yaml?branch=master&label=security&logo=github)](https://github.com/monsieurbiz/SyliusSalesReportsPlugin/actions?query=workflow%3ASecurity)


A simple plugin to have sales reports in Sylius

![Reports form](screenshots/reports_form.png)

## Compatibility

| Sylius Version | PHP Version     |
|----------------|-----------------|
| 1.12           | 8.1 - 8.2 - 8.3 |
| 1.13           | 8.1 - 8.2 - 8.3 |
| 1.14           | 8.1 - 8.2 - 8.3 |

## Installation

If you want to use our recipes, you can configure your composer.json by running:

```bash
composer config --no-plugins --json extra.symfony.endpoint '["https://api.github.com/repos/monsieurbiz/symfony-recipes/contents/index.json?ref=flex/master","flex://defaults"]'
```

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

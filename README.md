# Monsieur Biz Sylius sales report plugin

## Install

 Without symfony binary : 
 
`composer require monsieurbiz/sylius-sales-reports-plugin=dev-master`
 
 With symfony binary : 
 
`symfony composer require monsieurbiz/sylius-sales-reports-plugin=dev-master`

> We will make tagged release when the plugin development will be ended. Use it carefully until this moment

## Configure

Edit your `config/bundles.php` to add this line :

```
    MonsieurBiz\SyliusSalesReportsPlugin\MonsieurBizSyliusSalesReportsPlugin::class => ['all' => true],
``` 

And import plugin config in `config/packages/monsieur_biz_sales_report_plugin.yaml` :

```yaml
imports:
  - { resource: "@MonsieurBizSyliusSalesReportsPlugin/Resources/config/config.yaml" }
``` 


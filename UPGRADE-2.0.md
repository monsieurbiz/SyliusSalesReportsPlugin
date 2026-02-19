# Upgrade from 1.x to 2.x

## Requirements

- PHP 8.2 or 8.3
- Sylius 2.0

## Breaking Changes

### 1. File Structure Changes

Templates, config, and translations have been moved:

| Before (1.x) | After (2.0) |
|--------------|-------------|
| `src/Resources/views/` | `templates/` |
| `src/Resources/config/` | `config/` |
| `src/Resources/translations/` | `translations/` |

### 2. Routing Configuration

Update your routing configuration in `config/routes/monsieurbiz_sylius_sales_reports_plugin.yaml`:

```yaml
# Before (1.x)
monsieurbiz_sales_reports_plugin:
    resource: "@MonsieurBizSyliusSalesReportsPlugin/Resources/config/routing.yaml"

# After (2.x)
monsieurbiz_sales_reports_plugin:
    resource: "@MonsieurBizSyliusSalesReportsPlugin/config/routing.yaml"
```

### 3. Template Styling

Templates now use Bootstrap 5 instead of SemanticUI.

### 4. Twig Hooks

The plugin now uses Sylius 2.0 Twig Hooks system instead of Sonata blocks.

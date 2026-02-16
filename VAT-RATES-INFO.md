# EU VAT Rates Information

This document provides information about the VAT Rate Importer feature in EU VAT Guard for WooCommerce.

## Overview

The VAT Rate Importer allows you to easily import current EU VAT rates for all 27 member states directly into your WooCommerce tax settings. This eliminates the need to manually configure tax rates for each EU country.

## Features

### Country Selection
- Select individual countries or use "Select All" for all EU member states
- Only countries enabled in your WooCommerce selling locations are shown
- Real-time preview of standard and reduced rates for each country

### Rate Types

#### Standard Rates
- The main VAT rate applied to most goods and services
- Automatically imported for all selected countries
- Displayed as clean percentage (e.g., "21%") on checkout

#### Reduced Rates
- Lower VAT rates for specific categories of goods
- Common for essential items like food, books, and pharmaceuticals
- Optional import - can be enabled/disabled
- Imports the primary reduced rate per country into WooCommerce's default Reduced rate tax class

## Current EU VAT Rates (2024)

| Country | Standard | Reduced |
|---------|----------|---------|
| Austria | 20% | 10%, 13% |
| Belgium | 21% | 6%, 12% |
| Bulgaria | 20% | 9% |
| Croatia | 25% | 5%, 13% |
| Cyprus | 19% | 5%, 9% |
| Czech Republic | 21% | 10%, 15% |
| Denmark | 25% | - |
| Estonia | 20% | 9% |
| Finland | 24% | 10%, 14% |
| France | 20% | 5.5%, 10% |
| Germany | 19% | 7% |
| Greece | 24% | 6%, 13% |
| Hungary | 27% | 5%, 18% |
| Ireland | 23% | 9%, 13.5% |
| Italy | 22% | 4%, 5%, 10% |
| Latvia | 21% | 5%, 12% |
| Lithuania | 21% | 5%, 9% |
| Luxembourg | 17% | 3%, 8%, 14% |
| Malta | 18% | 5%, 7% |
| Netherlands | 21% | 9% |
| Poland | 23% | 5%, 8% |
| Portugal | 23% | 6%, 13% |
| Romania | 19% | 5%, 9% |
| Slovakia | 20% | 10% |
| Slovenia | 22% | 5%, 9.5% |
| Spain | 21% | 4%, 10% |
| Sweden | 25% | 6%, 12% |

## How It Works

### Import Process
1. Navigate to **EU VAT Guard > VAT Rate Importer** in your WordPress admin
2. Select the countries you want to import rates for
3. Choose whether to include reduced VAT rates
4. Click "Import Selected VAT Rates"

### WooCommerce Integration
- Creates or updates tax rates in WooCommerce tax tables
- Standard rates get priority 1, reduced rates get priority 2
- Reduced rates use WooCommerce's default reduced tax class (translation-safe detection)
- Automatically clears WooCommerce tax cache

### Tax Class Assignment
After importing, you can assign the Reduced rate class to relevant products:
1. Go to **Products > Edit Product**
2. In the **General** tab, find **Tax Class**
3. Select **Reduced rate** (or its localized label, depending on store language)

## Best Practices

### Before Importing
- Backup your WooCommerce tax settings
- Review your current tax configuration
- Ensure your store's base country is correctly set

### After Importing
- Review the imported rates in **WooCommerce > Settings > Tax**
- Test checkout with different countries and tax classes
- Update product tax classes as needed

### Maintenance
- VAT rates can change (though rarely)
- Re-run the importer to update rates when needed
- The tool will update existing rates rather than duplicate them

## Troubleshooting

### Common Issues

**No countries showing**
- Check that countries are enabled in **WooCommerce > Settings > General > Selling locations**

**Rates not applying at checkout**
- Verify tax calculation is enabled in WooCommerce
- Check that customer's country matches imported rates
- Ensure products have correct tax class assigned

**Duplicate rates**
- The importer prevents duplicates by checking existing rates
- If you see duplicates, they may have been created manually

### Support
For technical support or questions:
- Email: dev@stormlabs.be
- Website: https://stormlabs.be/

## Legal Notice

VAT rates are provided for convenience and are based on publicly available information as of 2024. Always verify current rates with official tax authorities before relying on them for business purposes. Stormlabs is not responsible for any tax compliance issues arising from the use of this tool.

# EU VAT Rates Information

This document provides information about the VAT Rate Importer feature in EU VAT Guard for WooCommerce.

## Overview

The VAT Rate Importer provides a bundled snapshot for all 27 EU member states, verified on **12 October 2026**. It imports the national standard rate and, optionally, one reduced-rate preset for each selected country. Rates are bundled with the plugin; they are not fetched automatically from a tax authority.

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
- Imports the first listed reduced rate per country into WooCommerce's default Reduced rate tax class
- This is a preset, not a legally defined "primary" rate. The correct rate depends on the goods or services sold
- Additional reduced, super-reduced, parking and livestock tiers appear in the overview but are not imported automatically

## EU VAT Rate Snapshot — 12 October 2026

The first percentage in the Reduced / special column is the optional import preset. Existing valid presets are retained; additional special tiers follow them. This overview covers national rates, not every exemption or special scheme. Zero rates and territorial exceptions need separate configuration. Portugal uses mainland rates; France uses metropolitan rates. Greek island reductions are excluded from country-wide imports.

| Country | Standard | Reduced / special (import preset first) |
|---------|----------|---------|
| Austria | 20% | 10%, 13%, 4.9% |
| Belgium | 21% | 6%, 12% |
| Bulgaria | 20% | 9% |
| Croatia | 25% | 5%, 13% |
| Cyprus | 19% | 5%, 9%, 3% |
| Czech Republic | 21% | 12% |
| Denmark | 25% | - |
| Estonia | 24% | 9%, 13% |
| Finland | 25.5% | 10%, 13.5% |
| France | 20% | 5.5%, 10%, 2.1% |
| Germany | 19% | 7% |
| Greece | 24% | 6%, 13%, 4% |
| Hungary | 27% | 5%, 18% |
| Ireland | 23% | 9%, 13.5%, 4.8% |
| Italy | 22% | 4%, 5%, 10% |
| Latvia | 21% | 5%, 12% |
| Lithuania | 21% | 5%, 12% |
| Luxembourg | 17% | 3%, 8%, 14% |
| Malta | 18% | 5%, 7%, 12% |
| Netherlands | 21% | 9% |
| Poland | 23% | 5%, 8% |
| Portugal | 23% | 6%, 13% |
| Romania | 21% | 11% |
| Slovakia | 23% | 5%, 19% |
| Slovenia | 22% | 5%, 9.5% |
| Spain | 21% | 4%, 10% |
| Sweden | 25% | 6%, 12% |

### Official sources and effective dates

The [EU Your Europe rate table](https://europa.eu/youreurope/business/finance-and-tax/vat/vat-rules-rates/index_en.htm), checked by the EU on 13 July 2026, supplies the baseline. National sources clarify missing tiers and newer changes:

- [Austria: Ministry of Finance](https://www.bmf.gv.at/rechtsnews/steuern-rechtsnews/aktuelle-infos-und-erlaesse/fachinformationen---umsatzsteuer/umsatzsteuersenkung-auf-ausgewaehlte-nahrungsmittel.html): 4.9% for specified food supplies from 1 July 2026; the 10% preset remains unchanged.
- [Cyprus: Tax Department](https://www.gov.cy/mof-tax/documents/syntelestes-f-p-a/): national tiers of 19%, 9%, 5% and 3%; the 5% preset remains unchanged.
- [Czechia: Financial Administration](https://financnisprava.gov.cz/cs/dane/danovy-system-cr/popis-systemu): 21% standard and 12% reduced; the former 10%/15% reduced rates were replaced from 1 January 2024.
- [Estonia: Tax and Customs Board](https://www.emta.ee/en/business-client/taxes-and-payment/value-added-tax/vat-rates-and-supply-exempt-tax/value-added-tax-rates): 24% standard from 1 July 2025, with reduced tiers of 9% and 13%.
- [Finland: Tax Administration](https://www.vero.fi/en/businesses-and-corporations/taxes-and-charges/vat/rates-of-vat/): 25.5% standard from 1 September 2024 and 13.5% reduced from 1 January 2026. The retained 10% preset applies to newspapers and magazines; food, books and many other categories use 13.5%.
- [France: tax administration](https://bofip.impots.gouv.fr/bofip/1380-PGP.html/identifiant=BOI-TVA-LIQ-10-20250514): metropolitan rates include the 2.1% special tier; the 5.5% preset remains unchanged.
- [Greece: AADE circular E.2059](https://www.aade.gr/egkyklioi-kai-apofaseis/e-2059-17-10-2023): a national 4% super-reduced service tier; the 6% preset remains unchanged. Country code `GR` is used for WooCommerce.
- [Ireland: Revenue](https://www.revenue.ie/en/vat/vat-rates/search-vat-rates/current-VAT-rates.aspx): 23%, 13.5%, 9% and a 4.8% livestock tier. The 4.5% farmer compensation percentage is a separate scheme, not a VAT rate to import.
- [Lithuania: VMI](https://www.vmi.lt/evmi/pvm-tarifai): the 9% reduced tier was replaced by 12% from 1 January 2026; the 5% tier remains.
- [Malta: Tax and Customs Administration](https://mtca.gov.mt/docs/default-source/documents/business-tax/vat/vat-guidelines/guidelines---12-percent-vat-rate-on-management-of-credit-and-credit-guarantees.pdf?sfvrsn=82dbe3e5_2): the 12% tier took effect on 1 January 2024; the 5% preset remains unchanged.
- [Romania: ANAF standard rate](https://static.anaf.ro/static/10/Anaf/AsistentaContribuabili_r/Cotele_de_TVA_09.2025.pdf) and [reduced-rate changes](https://static.anaf.ro/static/3/Cluj/20250912115924_cj_cotele_reduse_tva_12sep2025.pdf): 21% standard and 11% reduced from 1 August 2025. Transitional property relief is outside this importer.
- [Slovakia: Financial Administration](https://www.financnasprava.sk/sk/podnikatelia/dane/dan-z-pridanej-hodnoty/sadzby-dane): 23% standard and 5%/19% reduced from 1 January 2025; 2026 changes also affect which foods qualify.
- [Sweden: Skatteverket](https://www.skatteverket.se/omoss/pressochmedia/nyheter/2026/nyheter/livsmedelsmomsensankstill6procent.5.70685bee19c85dd5dd0a3f.html): food moved to 6% from 1 April 2026; 12% remains applicable to other categories, so the numerical tiers are unchanged.

## How It Works

### Import Process
1. Navigate to **EU VAT Guard > VAT Rate Importer** in your WordPress admin
2. Select the countries you want to import rates for
3. Choose whether to include reduced VAT rates
4. Click "Import Selected VAT Rates"

### WooCommerce Integration
- Creates a rate when no row matches the country, class and percentage; otherwise updates that matching row
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

### Updating an existing store to 1.5.0

Updating the plugin does not change saved WooCommerce tax rates. Export your tax settings first. Review the affected country/class rows and remove obsolete percentages deliberately before importing the new snapshot. The importer matches by percentage, so importing 24% for Estonia will not replace an old 20% or 22% row automatically. Regional, location-specific and custom rates must be reviewed separately.

After import, verify totals for the affected countries and product classes. A single reduced class cannot represent every product category. Configure additional tax classes and rates manually where your catalog needs them.

## Troubleshooting

### Common Issues

**No countries showing**
- Check that countries are enabled in **WooCommerce > Settings > General > Selling locations**

**Rates not applying at checkout**
- Verify tax calculation is enabled in WooCommerce
- Check that customer's country matches imported rates
- Ensure products have correct tax class assigned

**Duplicate rates**
- An exact country/class/percentage match is reused
- A changed percentage can leave an obsolete row alongside the new rate; review priorities and locations before removing it

### Support
For technical support or questions:
- Email: dev@stormlabs.be
- Website: https://stormlabs.be/

## Legal Notice

This is a dated configuration aid, not a product classification service. Verify the applicable product and territorial rules with the linked tax authorities. Rates can change after 12 October 2026.

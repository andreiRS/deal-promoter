# Creators API PHP SDK Example

> Vendored from the official Amazon CreatorsAPI PHP SDK v1.2.0 (Apache-2.0) for
> use as a Composer path repository. The only local modification is the addition
> of a `"name": "amazon/creatorsapi-php-sdk"` field to `composer.json` (the
> upstream package omits it, which a path repo requires). Permitted under
> Apache-2.0. Source: https://affiliate-program.amazon.com/creatorsapi
> `examples/` is intentionally not vendored.

## Prerequisites

### PHP Version Support
- **Supported**: To run the SDK you need PHP version 8.1 or higher.

## Setup Instructions

### 1. Install and Configure PHP

For PHP installation, you can download it from the official website: https://www.php.net/downloads

```bash
# Check PHP version
php --version
```

### 2. Install Dependencies
```bash
cd {path_to_dir}/creatorsapi-php-sdk
composer install
```

### 3. Run Sample Code
Navigate to the examples directory to run the samples.

```bash
cd examples
```

Before running the samples, you'll need to configure your API credentials in the sample files by replacing the following placeholders:

- `<YOUR CREDENTIAL ID>` - Your API credential ID
- `<YOUR CREDENTIAL SECRET>` - Your API credential secret  
- `<YOUR CREDENTIAL VERSION>` - Your credential version (e.g., "2.1" for NA, "2.2" for EU, "2.3" for FE with Cognito; "3.1" for NA, "3.2" for EU, "3.3" for FE with LWA)
- `<YOUR MARKETPLACE>` - Your marketplace (e.g., "www.amazon.com" for US marketplace)
- `<YOUR PARTNER TAG>` - Add valid Partner Tag for the requested marketplace in applicable sample code snippet files

Run the following commands to run the sample files:

**Get detailed product information:**
```bash
php SampleGetItems.php
```

**Search for products:**
```bash
php SampleSearchItems.php
```

#### Other Samples
Check the `examples` directory for additional sample files with various API operations.

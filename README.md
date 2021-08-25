# Magento 2 Dump Missing image entries

![phpcs](https://github.com/DominicWatts/MissingMedia/workflows/phpcs/badge.svg)

![PHPCompatibility](https://github.com/DominicWatts/MissingMedia/workflows/PHPCompatibility/badge.svg)

![PHPStan](https://github.com/DominicWatts/MissingMedia/workflows/PHPStan/badge.svg)

![php-cs-fixer](https://github.com/DominicWatts/MissingMedia/workflows/php-cs-fixer/badge.svg)


Scan and dump media gallery entries for images that are missing due to migration or data loss.

Search for products with no media entries.

## Install instructions

    composer require dominicwatts/missingmedia

    php bin/magento setup:upgrade

    php bin/magento setup:di:compile

## Usage instructions

### Find and resolve products with missing media in file system

    xigen:missingmedia:scan

    php bin/magento xigen:missingmedia:scan

To also render output in a console table use verbose output

    php bin/magento xigen:missingmedia:scan -v 

Check `./pub/media/xigen/missing-product-image-export.csv`

Create `./pub/media/xigen` if not exist

To delete entries with missing media use -d parameter

php bin/magento xigen:missingmedia:scan -d true

###  Find products with no media entries

    xigen:missingmedia:missing [--] <enabled>

php bin/magento xigen:missingmedia:missing

To also render output in a console table use verbose output

    php bin/magento xigen:missingmedia:missing -v 
    
To search only enabled products use 'enabled' argument

    php bin/magento xigen:missingmedia:missing enabled -v

Check `./pub/media/xigen/no-product-image-export.csv`

Create `./pub/media/xigen` if not exist

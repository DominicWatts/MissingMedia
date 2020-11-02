# Magento 2 Dump Missing image entries

Scan and dump media gallery entries for images that are missing due to migration or data loss.

## Install instructions

    composer require dominicwatts/missingmedia

    php bin/magento setup:upgrade

    php bin/magento setup:di:compile

## Usage instructions

    xigen:missingmedia:scan

    php bin/magento xigen:missingmedia:scan

To also render output in a console table use verbose output

    php bin/magento xigen:missingmedia:scan -v 

Check `xigen/missing-product-image-export.csv`

Create `./pub/media/xigen` if not exist
# 3.1.1
* Fix incorrect :id parameter in ExportHandler

# 3.1.0
* Added authorization token to all feeds. Go to Hello Retail sales channels and generate token

# 3.0.11
* Improve cart tracker: Cart is now tracked on every page and offcanvas-cart open

# 3.0.10

* Fixed issue where feeds were generated even if the sales channel is marked as inactive

# 3.0.9

* Changed: "Save" button to not force generate feeds when Hello Retail sales channel is saved
* Added: Button to force generate feeds Hello Retail sales channel(s) (Administration)
* Category feed now only takes categories associated with the selected sales channel (Navigation, Footer & service menu)

# 3.0.8

* Fixed a bug in administration with saveFinish

# 3.0.7

* Fixed issue where administration broke when creating a new sales channel (Hello Retail)
* Added info message on "saveFinish"

# 3.0.6

* Made the option to include products in category feed default to OFF / false

# 3.0.5

* Fixed category body template error

# 3.0.4

* Made the option to include products in category feed default to ON / true for backwards compatibility
* Remove test code hardcoding the include products in category feed to false

# 3.0.3

* Fixed category export feed to not load product_stream products unless it's necessary
* Added option to include products in category feed (Default, false)

# 3.0.2

* Correction to "GET" request in administration

# 3.0.1

* Removed "product" association to avoid missing association warning in error log

# 3.0.0

* Added controller to ensure files can load cross sales channels using subfolder domain
* Changed administration to save default/new feed values as empty to allow overrides on default template

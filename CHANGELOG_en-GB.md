# 6.0.3
* Added click tracking to cart recommendations
* Made functions in RecommendationService protected instead of private

# 6.0.2
* Added cart price to cart recommendations.

# 6.0.1
* Changed lineItem lookup to use reference id instead of id
* Added filter on cart lineItems to filter out discounts

# 6.0.0
* Shopware 6.7 compatibility

# 5.3.4
* Moved client-side script initialization earlier in the page load cycle
* Removed legacy cart tracking logic
* General code quality

# 5.3.3
* Added Search option
* Feeds:
    * Product feed
        * Newly added:
            * `extraDataList`.`*`
            * `extraData`.`parentId`
            * `extraData`.`displayGroup`
            * `extraData`.`manufacturerId`
            * `extraData`.`isCloseoutAvailable`
        * Changed:
            * Translatable to use `translation('key')`
            * Moved auto mapped to `extraData` & `extraDataList`
        * Corrected:
            * `productnumber` => `productNumber`
            * `instock` => `inStock`
            * `imgurl` => `imgUrl`
    * Category feed
        * Newly added:
            * `extraData`.`*`
        * Changed:
            * Translatable to use `translation('key')`

# 5.3.2
* Update and add snippets in administration.

# 5.3.1
* Better error handling in cart recommendations
* Added option to request pages by category id

# 5.3.0
* Added the option to choose the placement of recommendation boxes: sidebar (default) or embedded in the offcanvas.

# 5.2.2
* Fixed bug in feed generation template

# 5.2.1
* Added API request safeguard and better handling
* Added custom log handler to the API requests

# 5.2.0
* Added the option to replace Shopware product listing with hello retail pages using page key

# 5.1.0
* Added functionality to get product recommendations in offcanvas cart
* Replaced javascript recommendations block with server side api call to hello retail

# 5.0.0
* Compatibility for Shopware 6.6
* Added authorization token to all feeds. Go to Hello Retail sales channels and generate token

# 4.4.0
* Added the option to replace Shopware product listing with hello retail pages using page key

# 4.3.1
* Bugfix: Remove Sales Channel Context from export message

# 4.3.0
* Added functionality to get product recommendations in offcanvas cart
* Replaced javascript recommendations block with server side api call to hello retail

# 4.2.1
* Fixed bug regarding message queue serialization

# 4.2.0
* Added authorization token to all feeds. Go to Hello Retail sales channels and generate token

# 4.1.0
* Fixed some message queue serialization errors that could occur on Shopware 6.5.x

# 4.0.2
* Fix tracking by moving script to the layout_head_javascript_tracking block

# 4.0.1
* Added missing scheduled task parameter for scheduled task handler

# 4.0.0
* Compatibility for Shopware 6.5

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

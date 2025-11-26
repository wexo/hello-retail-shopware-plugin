# 6.0.2
* Klick-Tracking zu den Warenkorb-Empfehlungen hinzugefügt
* Funktionen im RecommendationService statt privat nun geschützt gemacht

# 6.0.1
* LineItem-Suche geändert, um Referenz-ID anstelle von ID zu verwenden
* Filter für Warenkorb-LineItems hinzugefügt, um Rabatte herauszufiltern

# 6.0.0
* Shopware 6.7 Kompatibilität

# 5.3.4
* Clientseitige Skriptinitialisierung wurde früher im Seitenladezyklus platziert.
* Veraltete Warenkorb-Tracking-Logik entfernt.
* Allgemeine Codequalität

# 5.3.3
* Suchoption hinzugefügt
* Feeds:
    * Produkt-Feed
        * Neu hinzugefügt:
            * `extraDataList`.`*`
            * `extraData`.`parentId`
            * `extraData`.`displayGroup`
            * `extraData`.`manufacturerId`
            * `extraData`.`isCloseoutAvailable`
        * Geändert:
            * Translatable to use `translation('key')`
            * Moved auto mapped to `extraData`
        * Korrigiert:
            * `productnumber` => `productNumber`
            * `instock` => `inStock`
            * `imgurl` => `imgUrl`
    * Kategorie-Feed
        * Neu hinzugefügt:
            * `extraData`.`*`
        * Geändert:
            * Translatable to use `translation('key')`

# 5.3.2
* Aktualisieren und fügen Sie Snippets in der Verwaltung hinzu.

# 5.3.1
* Bessere Fehlerbehandlung bei Warenkorb-Empfehlungen
* Option zum Anfordern von Seiten nach Kategorie-ID hinzugefügt

# 5.3.0
* Option zur Auswahl der Platzierung von Empfehlungsfeldern hinzugefügt: Seitenleiste (Standard) oder eingebettet in den Offcanvas.

# 5.2.2
* Fehler in der Vorlage zur Feed-Generierung behoben

# 5.2.1
* API-Anforderungsschutz und bessere Handhabung hinzugefügt
* Benutzerdefinierter Protokollhandler für die API-Anforderungen hinzugefügt

# 5.2.0
* Option hinzugefügt, Shopware-Produktlisten mithilfe des Seitenschlüssels durch Hello-Retail-Seiten zu ersetzen

# 5.1.0
* Funktion zum Abrufen von Produktempfehlungen im OffCanvas-Warenkorb hinzugefügt.
* JavaScript-Empfehlungsblock durch serverseitigen API-Aufruf an Hello Retail ersetzt

# 5.0.0
* Kompatibilität für Shopware 6.5
* Autorisierungstoken zu allen Feeds hinzugefügt. Gehen Sie zu den Hello Retail-Vertriebskanälen und generieren Sie ein Token

# 4.4.0
* Option hinzugefügt, Shopware-Produktlisten mithilfe des Seitenschlüssels durch Hello-Retail-Seiten zu ersetzen

# 4.3.1
* Bugfix: Sales Channel Context aus Exportnachricht entfernen

# 4.3.0
* Funktion zum Abrufen von Produktempfehlungen im OffCanvas-Warenkorb hinzugefügt.
* JavaScript-Empfehlungsblock durch serverseitigen API-Aufruf an Hello Retail ersetzt

# 4.2.1
* Fehler bezüglich der Serialisierung der Nachrichtenwarteschlange behoben

# 4.2.0
* Autorisierungstoken zu allen Feeds hinzugefügt. Gehen Sie zu den Hello Retail-Vertriebskanälen und generieren Sie ein Token

# 4.1.0
* Einige Fehler bei der Serialisierung der Nachrichtenwarteschlange behoben, die unter Shopware 6.5.x auftreten konnten

# 4.0.2
* Korrigieren Sie die Nachverfolgung, indem Sie das Skript in den Block „layout_head_javascript_tracking“ verschieben

# 4.0.1
* Fehlender Parameter für geplante Aufgaben für den Handler für geplante Aufgaben hinzugefügt

# 4.0.0
* Kompatibilität für Shopware 6.5

# 3.0.11
* Warenkorb-Tracker verbessern: Der Warenkorb wird jetzt auf jeder Seite verfolgt und der Offcanvas-Warenkorb ist geöffnet

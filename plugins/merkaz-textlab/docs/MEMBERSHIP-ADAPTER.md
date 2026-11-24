# Membership / paywall adapter

TextLab deliberately does not depend on a particular billing or membership plugin.

The simplest integration is to make the external membership system assign a WordPress role and map that role to a TextLab bundle under **TextLab → Settings & Access**.

If the membership system does not use roles, add a small site/plugin adapter using this filter:

```php
add_filter( 'mtl_external_user_capabilities', function( $caps, $user_id ) {
    // Replace this with the membership plugin's own active-access test.
    if ( my_site_user_has_advanced_textlab_access( $user_id ) ) {
        $caps = array_merge( $caps, array(
            'mtl_translate',
            'mtl_sources',
            'mtl_advanced_annotations',
            'mtl_advanced_export',
            'mtl_collaborate',
            'mtl_import_data',
        ) );
    }
    return $caps;
}, 10, 2 );
```

For access promised permanently to a former subscriber, prefer a durable TextLab entitlement instead of having the adapter infer entitlement from current billing status.

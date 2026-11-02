#!/usr/bin/env python3
"""
Copy the legacy hotel tables out of a mysqldump into a development database
under their own prefix, for the M18 importer (`--source-prefix=legacy_`).

    bin/copy-legacy-tables.py <dump.sql> > legacy.sql
    mysql … <dev-db> < legacy.sql

Keeps only the tables the importer reads (the `hbfwc_*` tables and the
WordPress / WooCommerce tables that hold rooms, rates, guests, staff,
bookings and settings) and renames them `<from>x` → `<to>x` (default
`wp_` → `legacy_`), so the development site's own tables are never touched.
Only table identifiers are renamed: meta keys such as `wp_capabilities`
keep the legacy prefix, which the importer detects from the source.

Development and staging only. On production the legacy plugins and the new
ones share one database, and the importer reads the live tables directly.
"""
import re
import sys

WP_TABLES = {
    'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
    'users', 'usermeta', 'options',
    'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'wc_customer_lookup',
    'woocommerce_order_items', 'woocommerce_order_itemmeta', 'comments', 'commentmeta',
}


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    path = sys.argv[1]
    src = sys.argv[2] if len(sys.argv) > 2 else 'wp_'
    dst = sys.argv[3] if len(sys.argv) > 3 else 'legacy_'

    def wanted(table):
        if not table.startswith(src):
            return False
        name = table[len(src):]
        return name.startswith('hbfwc_') or name in WP_TABLES

    section = re.compile(r'^-- (?:Table structure|Dumping data) for table `([^`]+)`')
    header = re.compile(r'^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO|LOCK TABLES|/\*!40000 ALTER TABLE|ALTER TABLE) `' + re.escape(src))
    keep = False
    out = sys.stdout
    kept = set()
    with open(path, 'r', encoding='utf-8', errors='surrogateescape') as dump:
        for line in dump:
            m = section.match(line)
            if m:
                keep = wanted(m.group(1))
                if keep:
                    kept.add(m.group(1))
            if line.startswith('/*!') and not header.match(line) and not keep:
                out.write(line)  # session SET … lines around the dump
                continue
            if not keep:
                continue
            if header.match(line):
                line = line.replace('`' + src, '`' + dst, 1)
            out.write(line)
    sys.stderr.write('copied %d tables as %s*: %s\n' % (len(kept), dst, ', '.join(sorted(t[len(src):] for t in kept))))


if __name__ == '__main__':
    main()

#! /bin/bash

echo NOTES ONLY - RUN REQUIRED COMMANDS MANUALLY!!
exit

# Schema mapping now lives in #[ORM\...] attributes on the classes in
# application/Entities/ (was XML under doctrine2/xml, removed). There is no
# generate-from-XML step any more, and orm:generate-entities/-repositories were
# removed in Doctrine ORM 3 — edit the entity classes directly, then regenerate
# proxies:
./doctrine2-cli.php orm:generate-proxies


echo "####   ./doctrine2-cli.php orm:schema-tool:drop --force && ./doctrine2-cli.php orm:schema-tool:create "
echo "####   ./doctrine2-cli.php orm:schema-tool:create "


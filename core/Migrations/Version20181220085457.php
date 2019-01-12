<?php
namespace OC\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use OCP\Migration\ISchemaMigration;

/**
 * Auto-generated migration step: Please modify to your needs!
 */
class Version20181220085457 implements ISchemaMigration {
	public function changeSchema(Schema $schema, array $options) {
		$prefix = $options['tablePrefix'];

		if ($schema->hasTable("${prefix}share")) {
			$shareTable = $schema->getTable("${prefix}share");

			if (!$shareTable->hasColumn('attributes')) {
				$shareTable->addColumn(
					'attributes',
					Type::TEXT,
					[
						'default' => null,
						'notnull' => false
					]
				);
			}
		}
	}
}

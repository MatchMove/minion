<?php

/**
 * The migration manager is responsible for locating migration files, syncing 
 * them with the migrations table in the database and selecting any migrations 
 * that need to be executed in order to reach a target version
 *
 * @author Matt Button <matthew@sigswitch.com>
 **/
class Minion_Migration_Manager {

	/**
	 * The database connection that sould be used
	 * @var Kohana_Database
	 */
	protected $_db;

	/**
	 * Model used to interact with the migrations table in the database
	 * @var Model_Minion_Migration
	 */
	protected $_model;

	/**
	 * Constructs the object, allows injection of a Database connection
	 *
	 * @param Kohana_Database        The database connection that should be passed to migrations
	 * @param Model_Minion_Migration Inject an instance of the minion model into the manager
	 */
	public function __construct(Kohana_Database $db, Model_Minion_Migration $model = NULL)
	{
		if($model === NULL)
		{
			$model = new Model_Minion_Migration($db);
		}

		$this->_db    = $db;
		$this->_model = $model;
	}

	/**
	 * Set the database connection to be used
	 * 
	 * @param Kohana_Database Database connection
	 * @return Minion_Migration_Manager
	 */
	public function set_db(Kohana_Database $db)
	{
		$this->_db = $db;

		return $this;
	}

	/**
	 * Set the model to be used in the rest of the app
	 *
	 * @param Model_Minion_Migration Model instance
	 * @return Minion_Migration_Manager
	 */
	public function set_model(Model_Minion_Migration $model)
	{
		$this->_model = $model;

		return $this;
	}

	/**
	 * Run migrations in the specified locations so as to reach specified targets
	 *
	 * There are three methods for specifying target versions:
	 *
	 * 1. Pass them in with the array of locations, i.e.
	 *
	 *     array(
	 *       location => target_version
	 *     )
	 *
	 * 2. Pass them in separately, with param1 containing an array of 
	 * locations like:
	 *
	 *     array(
	 *       location,
	 *       location2,
	 *     )
	 *
	 * And param2 containing an array structured in the same way as in #1
	 *
	 * 3. Perform a mix of the above two methods
	 *
	 * It may seem odd to use two arrays to specify locations and versions, but 
	 * it's this way to allow users to upgrade / downgrade all locations while 
	 * migrating a specific location to a specific version
	 *
	 * If no locations are specified then migrations from all locations will be 
	 * run and be brought up to the latest available version
	 *
	 * @param  array   Set of locations to update, empty array means all
	 * @param  array   Versions for specified locations
	 * @param  boolean The default direction (up/down) for migrations without a specific version
	 * @param  boolean Whether successful migrations should be recorded
	 * @return array   Array of all migrations that were successfully applied
	 */
	public function run_migration(array $locations = array(), $versions = array(), $default_direction = TRUE, $record_success = TRUE)
	{
		$migrations = $this->_model->fetch_required_migrations($locations, $versions, $default_direction);

		foreach($migrations as $location)
		{
			$method = $location['direction'] ? 'up' : 'down';

			foreach($location['migrations'] as $migration)
			{
				$filename  = Minion_Migration_Util::get_filename_from_migration($migration);

				if( ! ($file  = Kohana::find_file('migrations', $filename, FALSE)))
				{
					throw new Kohana_Exception(
						'Cannot load migration :migration (:file)', 
						array(
							':migration' => $migration['id'], 
							':file'      => $filename
						)
					);
				}

				$class = Minion_Migration_Util::get_class_from_migration($migration);

				$this->_db->query(NULL, 'START TRANSACTION');

				try
				{
					include_once $file;

					$instance = new $class;

					$instance->$method($this->_db);
				}
				catch(Exception $e)
				{
					$this->_db->query(NULL, 'ROLLBACK');

					throw $e;
				}
				
				$this->_db->query('COMMIT');

				if($record_success)
				{
					$this->_model->mark_migration($migration, $location['direction']);
				}
			}
		}
	}

	/**
	 * Syncs all available migration files with the database
	 *
	 * @chainable
	 * @return Minion_Migration_Manager Chainable instance
	 */
	public function sync_migration_files()
	{
		// Get array of installed migrations with the id as key
		$installed = $this->_model->fetch_all('id');

		$available = $this->scan_for_migrations();

		$all_migrations = array_keys($installed) + array_keys($available);

		foreach($all_migrations as $migration)
		{
			// If this migration has since been deleted
			if(isset($installed[$migration]) AND ! isset($available[$migration]))
			{
				// We should only delete a record of this migration if it does 
				// not exist in the "real world"
				if($installed[$migration]['applied'] === '0')
				{
					$this->_model->delete_migration($installed[$migration]);
				}
			}
			// If the migration has not yet been installed :D
			elseif( ! isset($installed[$migration]) AND isset($available[$migration]))
			{
				$this->_model->add_migration($available[$migration]);
			}
			// Somebody changed the description of the migration, make sure we 
			// update it in the db as we use this to build the filename!
			elseif($installed[$migration]['description'] !== $available[$migration]['description'])
			{
				$this->_model->update_migration($installed[$migration], $available[$migration]);
			}
		}
		

		return $this;
	}

	/**
	 * Scans all migration directories for available migration files
	 *
	 * Returns an array of 
	 *
	 *   migration_id => array(
	 *   	'file'   => migration_file, 
	 *   	'location' => migration_location
	 *   );
	 *
	 * @param return array
	 */
	public function scan_for_migrations()
	{
		$files = Kohana::list_files('migrations');

		return Minion_Migration_Util::compile_migrations_from_files($files);
	}
}

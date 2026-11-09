<?php
/**
 * Residence TATA's departments and job titles.
 *
 * @package RadiusTheme\RadiusHotelBookingTata\Staff
 */

namespace RadiusTheme\RadiusHotelBookingTata\Staff;

defined( 'ABSPATH' ) || exit;

/**
 * The hotel's departments and job titles (Pro Settings → Staff defaults),
 * and the clean-up of the names the old system let staff type freely
 * ("Acceuil", "TECNICIEN DE SURFACE", a job title used as a department…):
 * the importers store the clean name, and a one-time pass fixes the staff
 * files and contracts of a site imported before this existed. A name it
 * does not know is kept as it is.
 */
final class StaffNames {

	/**
	 * Option stamped once the existing files are cleaned.
	 */
	const OPTION = 'rtbp_tata_staff_names';

	/**
	 * Version of the clean-up (bump to run it again after changing the maps).
	 */
	const VERSION = '1';

	/**
	 * The departments.
	 */
	const DEPARTMENTS = array( 'Accueil', 'Sécurité', 'Buanderie', 'Cuisine', 'Maintenance' );

	/**
	 * The job titles.
	 */
	const JOB_TITLES = array( 'Accueil', 'Superviseur', 'Agent de sécurité', 'Buandier', 'Cuisine', 'Technicien de surface', 'Technicienne de surface' );

	/**
	 * Department names as typed (key()) => the department.
	 */
	const DEPARTMENT_ALIASES = array(
		'accueil'                 => 'Accueil',
		'reception'               => 'Accueil',
		'securite'                => 'Sécurité',
		'agent de securite'       => 'Sécurité',
		'buanderie'               => 'Buanderie',
		'cuisine'                 => 'Cuisine',
		'maintenance'             => 'Maintenance',
		'technicien de surface'   => 'Maintenance',
		'technicienne de surface' => 'Maintenance',
	);

	/**
	 * Job titles as typed (key()) => the job title.
	 */
	const JOB_ALIASES = array(
		'accueil'                 => 'Accueil',
		'superviseur'             => 'Superviseur',
		'agent de securite'       => 'Agent de sécurité',
		'buandier'                => 'Buandier',
		'cuisine'                 => 'Cuisine',
		'technicien de surface'   => 'Technicien de surface',
		'technicienne de surface' => 'Technicienne de surface',
	);

	/**
	 * A job title => its department, for a file with no department.
	 */
	const JOB_DEPARTMENT = array(
		'Accueil'                 => 'Accueil',
		'Superviseur'             => 'Accueil',
		'Agent de sécurité'       => 'Sécurité',
		'Buandier'                => 'Buanderie',
		'Cuisine'                 => 'Cuisine',
		'Technicien de surface'   => 'Maintenance',
		'Technicienne de surface' => 'Maintenance',
	);

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rtbp_settings_schema', array( self::class, 'defaults' ), 20 );
		add_action( 'init', array( self::class, 'maybe_clean' ), 99 );
	}

	/**
	 * `rtbp_settings_schema`: the lists Pro's Settings → Staff starts with.
	 *
	 * @param array $sections Section => key => definition.
	 * @return array
	 */
	public static function defaults( $sections ): array {
		$sections = (array) $sections;
		if ( isset( $sections['pro_staff']['departments'] ) ) {
			$sections['pro_staff']['departments']['default'] = self::DEPARTMENTS;
		}
		if ( isset( $sections['pro_staff']['jobTitles'] ) ) {
			$sections['pro_staff']['jobTitles']['default'] = self::JOB_TITLES;
		}
		return $sections;
	}

	/**
	 * A name to compare: lower case, no accents, single spaces, the old
	 * system's typos fixed.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function key( string $name ): string {
		$key = strtolower( remove_accents( trim( preg_replace( '/\s+/u', ' ', $name ) ) ) );
		return strtr(
			$key,
			array(
				'acceuil'  => 'accueil',
				'tecnicien' => 'technicien',
			)
		);
	}

	/**
	 * The job title for a name as typed (unknown: kept, trimmed).
	 *
	 * @param string $job Job title.
	 * @return string
	 */
	public static function job( string $job ): string {
		$job = trim( $job );
		return self::JOB_ALIASES[ self::key( $job ) ] ?? $job;
	}

	/**
	 * The department for a name as typed; an empty one comes from the job
	 * title (unknown: kept, trimmed).
	 *
	 * @param string $department Department.
	 * @param string $job        Job title (as typed or clean).
	 * @return string
	 */
	public static function department( string $department, string $job = '' ): string {
		$department = trim( $department );
		if ( '' === $department ) {
			return self::JOB_DEPARTMENT[ self::job( $job ) ] ?? '';
		}
		return self::DEPARTMENT_ALIASES[ self::key( $department ) ] ?? $department;
	}

	/**
	 * Clean the staff files and contracts once (a site imported before the
	 * importers did it). Retried until Pro's tables exist.
	 *
	 * @return void
	 */
	public static function maybe_clean(): void {
		if ( get_option( self::OPTION ) === self::VERSION || ! function_exists( 'rtbp_table_prefix' ) ) {
			return;
		}
		$done = self::clean();
		if ( null !== $done ) {
			update_option( self::OPTION, self::VERSION, false );
		}
	}

	/**
	 * Fix every staff file and contract whose names differ from the clean ones.
	 *
	 * @return array<string, int>|null Rows changed per table; null when Pro's tables are missing.
	 */
	public static function clean(): ?array {
		global $wpdb;
		$changed = array();
		foreach ( array( 'pro_employees', 'pro_employee_contracts' ) as $name ) {
			$table = rtbp_table_prefix() . $name;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a one-time clean-up.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return null;
			}
			$changed[ $name ] = 0;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a one-time clean-up.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, job_title, department FROM %i', $table ), ARRAY_A ) as $row ) {
				$job        = self::job( (string) $row['job_title'] );
				$department = self::department( (string) $row['department'], $job );
				if ( $job === (string) $row['job_title'] && $department === (string) $row['department'] ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a one-time clean-up.
				$wpdb->update(
					$table,
					array(
						'job_title'  => $job,
						'department' => $department,
					),
					array( 'id' => (int) $row['id'] )
				);
				++$changed[ $name ];
			}
		}
		return $changed;
	}
}

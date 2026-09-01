<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class CN_DB {
	public static function tabla( $nombre ) {
		global $wpdb;
		return $wpdb->prefix . 'cn_' . $nombre;
	}
	const DB_VERSION = '2.3.0';
	public static function maybe_upgrade() {
		if ( get_option( 'cn_db_version' ) !== self::DB_VERSION ) {
			self::instalar();
			update_option( 'cn_db_version', self::DB_VERSION );
		}
	}
	public static function instalar() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		$t_miembros         = self::tabla( 'miembros' );
		$t_sesiones         = self::tabla( 'sesiones' );
		$t_cursos           = self::tabla( 'cursos' );
		$t_videos           = self::tabla( 'videos' );
		$t_contenidos       = self::tabla( 'contenidos' );
		$t_progreso         = self::tabla( 'progreso' );
		$t_vistos           = self::tabla( 'vistos' );
		$t_preapprovals     = self::tabla( 'preapprovals_pendientes' );
		$t_trial_pendientes = self::tabla( 'trial_pendientes' );
		$t_mp_log           = self::tabla( 'mp_log' );
		$sql = "CREATE TABLE {$t_miembros} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			nombre_apellido varchar(191) NOT NULL,
			celular_hash varchar(255) NOT NULL,
			celular_hint varchar(4) NOT NULL,
			estado ENUM('activo','pausado','cancelada') NOT NULL DEFAULT 'activo',
			preapproval_id varchar(191) DEFAULT NULL,
			fecha_alta datetime NOT NULL,
			fecha_fin_trial datetime DEFAULT NULL,
			trial_payment_id varchar(191) DEFAULT NULL,
			trial_monto decimal(10,2) DEFAULT NULL,
			fecha_modificacion datetime DEFAULT NULL,
			email varchar(191) DEFAULT NULL,
			notas text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY celular_hint (celular_hint),
			KEY preapproval_id (preapproval_id),
			UNIQUE KEY trial_payment_id_unique (trial_payment_id)
		) {$charset_collate};
		CREATE TABLE {$t_sesiones} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			miembro_id bigint(20) unsigned NOT NULL,
			token varchar(64) NOT NULL,
			expira datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY token (token),
			KEY miembro_id (miembro_id)
		) {$charset_collate};
		CREATE TABLE {$t_cursos} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			nombre varchar(191) NOT NULL,
			link_drive varchar(500) NOT NULL,
			orden int(11) NOT NULL DEFAULT 0,
			color varchar(20) DEFAULT NULL,
			drive_synced_at datetime DEFAULT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};
		CREATE TABLE {$t_videos} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			titulo varchar(191) NOT NULL,
			link_youtube varchar(500) NOT NULL,
			curso_id bigint(20) unsigned DEFAULT NULL,
			orden int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY curso_id (curso_id)
		) {$charset_collate};
		CREATE TABLE {$t_contenidos} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			curso_id bigint(20) unsigned NOT NULL,
			tipo ENUM('video','pdf','audio') NOT NULL,
			titulo varchar(191) NOT NULL,
			link varchar(500) NOT NULL,
			drive_file_id varchar(191) DEFAULT NULL,
			duracion varchar(20) DEFAULT NULL,
			fuente ENUM('manual','drive_auto') NOT NULL DEFAULT 'manual',
			orden int(11) NOT NULL DEFAULT 0,
			fecha_alta datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY curso_id (curso_id),
			KEY tipo (tipo),
			KEY drive_file_id (drive_file_id)
		) {$charset_collate};
		CREATE TABLE {$t_progreso} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			miembro_id bigint(20) unsigned NOT NULL,
			curso_id bigint(20) unsigned NOT NULL,
			contenido_id bigint(20) unsigned NOT NULL,
			actualizado datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY miembro_id (miembro_id)
		) {$charset_collate};
		CREATE TABLE {$t_vistos} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			miembro_id bigint(20) unsigned NOT NULL,
			contenido_id bigint(20) unsigned NOT NULL,
			fecha datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY miembro_contenido (miembro_id, contenido_id)
		) {$charset_collate};
		CREATE TABLE {$t_preapprovals} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			nombre_apellido varchar(191) NOT NULL,
			celular varchar(20) NOT NULL,
			external_reference varchar(191) NOT NULL,
			fecha datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY external_reference (external_reference),
			KEY celular (celular)
		) {$charset_collate};
		CREATE TABLE {$t_trial_pendientes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			nombre_apellido varchar(191) NOT NULL,
			celular varchar(20) NOT NULL,
			external_reference varchar(191) NOT NULL,
			fecha datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY external_reference (external_reference),
			KEY celular (celular)
		) {$charset_collate};
		CREATE TABLE {$t_mp_log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			payload longtext NOT NULL,
			tipo varchar(50) DEFAULT NULL,
			fecha datetime NOT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};";
		dbDelta( $sql );
	}
}

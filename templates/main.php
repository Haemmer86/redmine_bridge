<?php
/**
 * Wird nur angezeigt, solange die Redmine-Anbindung noch nicht eingerichtet
 * ist (siehe PageController::index()). Die Verbindungsprüfung selbst steht in
 * den Admin-Einstellungen (templates/settings/admin.php).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<div id="redmine-bridge-main" style="padding: 40px; max-width: 600px;">
	<h1><?php p($l->t('Redmine')); ?></h1>
	<p>
		<?php p($l->t('Noch keine Verbindung eingerichtet.')); ?>
		<?php p($l->t('Ein Administrator muss zunächst unter Einstellungen → Administration → Redmine die Zugangsdaten eintragen.')); ?>
	</p>
</div>

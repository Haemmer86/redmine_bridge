<?php
/**
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<div id="redmine-bruecke-main" style="padding: 40px; max-width: 600px;">
	<h1><?php p($l->t('Redmine')); ?></h1>

	<?php if (!$_['konfiguriert']): ?>
		<p>
			<?php p($l->t('Noch keine Verbindung eingerichtet.')); ?>
			<?php p($l->t('Ein Administrator muss zunächst unter Einstellungen → Administration → Redmine die Zugangsdaten eintragen.')); ?>
		</p>
	<?php else: ?>
		<p style="color: #c62828;">
			✗ <?php p($l->t('Verbindung fehlgeschlagen')); ?>: <?php p($_['verbindung']['fehler'] ?? ''); ?>
		</p>
	<?php endif; ?>
</div>

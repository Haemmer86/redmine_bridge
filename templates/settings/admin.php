<?php
/**
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<div id="redmine-bridge-settings" class="section">
	<h2><?php p($l->t('Redmine — Verbindung')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Adresse und API-Schlüssel deiner Redmine-Instanz. Den API-Schlüssel findest du in Redmine unter "Mein Konto" → "API-Zugriffsschlüssel anzeigen".')); ?>
	</p>

	<?php if ($_['verbindung'] !== null): ?>
		<?php if ($_['verbindung']['ok']): ?>
			<p><strong style="color: #2e7d32;">✔ <?php p($l->t('Verbindung erfolgreich')); ?></strong>
				— <?php p($l->t('angemeldet als')); ?> <?php p($_['verbindung']['benutzer']); ?></p>
		<?php else: ?>
			<p><strong style="color: #c62828;">✗ <?php p($l->t('Verbindung fehlgeschlagen')); ?></strong>
				— <?php p($_['verbindung']['fehler']); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<form method="post" action="<?php print_unescaped($_['actionUrl']); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">

		<label for="redmine-basis-url"><?php p($l->t('Redmine-Adresse')); ?></label>
		<br>
		<input
			type="text"
			id="redmine-basis-url"
			name="basisUrl"
			value="<?php p($_['basisUrl']); ?>"
			style="width: 400px; max-width: 100%;"
			placeholder="https://projekte.hammerits.cloud"
		>
		<br><br>

		<label for="redmine-api-schluessel"><?php p($l->t('API-Schlüssel')); ?></label>
		<br>
		<input
			type="password"
			id="redmine-api-schluessel"
			name="apiSchluessel"
			style="width: 400px; max-width: 100%;"
			placeholder="<?php p($_['hatSchluessel'] ? $l->t('••••••••  (bereits hinterlegt — nur bei Änderung ausfüllen)') : $l->t('API-Schlüssel eintragen')); ?>"
			autocomplete="off"
		>
		<br><br>

		<label for="redmine-basis-pfad"><?php p($l->t('Ablage-Wurzelpfad für Ticket-Ordner')); ?></label>
		<br>
		<input
			type="text"
			id="redmine-basis-pfad"
			name="basisPfad"
			value="<?php p($_['basisPfad']); ?>"
			style="width: 400px; max-width: 100%;"
			placeholder="Redmine"
		>
		<p class="settings-hint">
			<?php p($l->t('Relativ zum persönlichen Dateibereich bzw. zu einem dort eingebundenen externen Speicher (z. B. "IKS-Daten/Redmine", falls eine passende Freigabe eingebunden ist). Struktur darunter: Projekt / Jahr / Ticketnummer - Betreff.')); ?>
		</p>
		<br>

		<input type="submit" class="primary" value="<?php p($l->t('Speichern')); ?>">
	</form>
</div>

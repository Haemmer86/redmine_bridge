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

<div id="redmine-bridge-odoo-settings" class="section">
	<h2><?php p($l->t('Odoo — Sammelrechnungen')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Zugangsdaten deiner Odoo-Instanz, um aus mehreren ausgewählten Tickets einen Rechnungsentwurf anzulegen. In Odoo unter Einstellungen → Benutzer einen eigenen API-Benutzer/-Schlüssel anlegen, statt das persönliche Odoo-Passwort hier einzutragen.')); ?>
	</p>

	<?php if ($_['odooVerbindung'] !== null): ?>
		<?php if ($_['odooVerbindung']['ok']): ?>
			<p><strong style="color: #2e7d32;">✔ <?php p($l->t('Verbindung erfolgreich')); ?></strong>
				— <?php p($l->t('angemeldet als')); ?> <?php p($_['odooVerbindung']['benutzer']); ?></p>
		<?php else: ?>
			<p><strong style="color: #c62828;">✗ <?php p($l->t('Verbindung fehlgeschlagen')); ?></strong>
				— <?php p($_['odooVerbindung']['fehler']); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<form method="post" action="<?php print_unescaped($_['actionUrl']); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">

		<label for="odoo-url"><?php p($l->t('Odoo-Adresse')); ?></label>
		<br>
		<input
			type="text"
			id="odoo-url"
			name="odooUrl"
			value="<?php p($_['odooUrl']); ?>"
			style="width: 400px; max-width: 100%;"
			placeholder="https://meine-firma.odoo.com"
		>
		<br><br>

		<label for="odoo-db"><?php p($l->t('Datenbank')); ?></label>
		<br>
		<input
			type="text"
			id="odoo-db"
			name="odooDb"
			value="<?php p($_['odooDb']); ?>"
			style="width: 400px; max-width: 100%;"
			placeholder="meine-firma"
		>
		<br><br>

		<label for="odoo-benutzer"><?php p($l->t('Benutzer (Login/E-Mail)')); ?></label>
		<br>
		<input
			type="text"
			id="odoo-benutzer"
			name="odooBenutzer"
			value="<?php p($_['odooBenutzer']); ?>"
			style="width: 400px; max-width: 100%;"
			placeholder="rechnung@hammerits.de"
			autocomplete="off"
		>
		<br><br>

		<label for="odoo-api-schluessel"><?php p($l->t('API-Schlüssel')); ?></label>
		<br>
		<input
			type="password"
			id="odoo-api-schluessel"
			name="odooApiSchluessel"
			style="width: 400px; max-width: 100%;"
			placeholder="<?php p($_['odooHatSchluessel'] ? $l->t('••••••••  (bereits hinterlegt — nur bei Änderung ausfüllen)') : $l->t('API-Schlüssel eintragen')); ?>"
			autocomplete="off"
		>
		<br><br>

		<input type="submit" class="primary" value="<?php p($l->t('Speichern')); ?>">
	</form>
</div>

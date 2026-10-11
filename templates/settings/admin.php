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

<div id="redmine-bridge-mitarbeiter-settings" class="section">
	<h2><?php p($l->t('Meine Tickets — eingeschränkte Mitarbeiter-Ansicht')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Mitglieder der hier gewählten Nextcloud-Gruppe sehen beim Öffnen der App NUR ihre eigenen, zugewiesenen Tickets (Status ändern, Kommentar hinzufügen) — die normale Ticketübersicht, Projektverwaltung, Zeiterfassung usw. bleibt ihnen versperrt. Ohne gewählte Gruppe ist diese Einschränkung aus, alle Nutzer sehen die normale Oberfläche wie bisher.')); ?>
	</p>

	<form method="post" action="<?php print_unescaped($_['actionUrl']); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
		<input type="hidden" name="mitarbeiterFormular" value="1">

		<label for="redmine-mitarbeiter-gruppe"><?php p($l->t('Mitarbeiter-Gruppe')); ?></label>
		<br>
		<select id="redmine-mitarbeiter-gruppe" name="mitarbeiterGruppe" style="width: 400px; max-width: 100%;">
			<option value=""><?php p($l->t('— keine (Einschränkung aus) —')); ?></option>
			<?php foreach ($_['gruppen'] as $gruppe): ?>
				<option value="<?php p($gruppe['gid']); ?>" <?php p($gruppe['gid'] === $_['ausgewaehlteGruppe'] ? 'selected' : ''); ?>>
					<?php p($gruppe['name']); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="settings-hint">
			<?php p($l->t('Die Gruppe selbst legst du in den Nextcloud-Benutzereinstellungen an/pflegst Mitglieder — hier wird nur ausgewählt, welche Gruppe gemeint ist.')); ?>
		</p>
		<br>

		<?php if ($_['ausgewaehlteGruppe'] === ''): ?>
			<p class="settings-hint"><?php p($l->t('Erst eine Gruppe wählen und speichern, um hier die Ticket-Zuordnung je Mitglied einzutragen.')); ?></p>
		<?php elseif (count($_['mitglieder']) === 0): ?>
			<p class="settings-hint"><?php p($l->t('Diese Gruppe hat aktuell keine Mitglieder.')); ?></p>
		<?php else: ?>
			<table>
				<thead>
					<tr>
						<th style="text-align: left; padding-right: 16px;"><?php p($l->t('Nextcloud-Benutzer')); ?></th>
						<th style="text-align: left;"><?php p($l->t('Zugewiesener Redmine-Bearbeiter')); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($_['mitglieder'] as $mitglied): ?>
						<?php $aktuelleZuordnung = $_['zuordnungen'][$mitglied['uid']]['redmine_user_id'] ?? 0; ?>
						<tr>
							<td style="padding: 4px 16px 4px 0;"><?php p($mitglied['name']); ?></td>
							<td style="padding: 4px 0;">
								<select name="zuordnung[<?php p($mitglied['uid']); ?>]" style="width: 300px; max-width: 100%;">
									<option value="0"><?php p($l->t('— keiner —')); ?></option>
									<?php foreach ($_['redmineBenutzer'] as $benutzer): ?>
										<option value="<?php p($benutzer['id']); ?>" <?php p((int)$benutzer['id'] === (int)$aktuelleZuordnung ? 'selected' : ''); ?>>
											<?php p($benutzer['name']); ?>
										</option>
									<?php endforeach; ?>
									<?php if ($aktuelleZuordnung > 0 && !in_array((int)$aktuelleZuordnung, array_column($_['redmineBenutzer'], 'id'), true)): ?>
										<!-- Bereits zugeordneter Redmine-Benutzer ist in der aktuellen Liste nicht (mehr) enthalten
										     (z. B. Redmine-Benutzerliste gerade nicht abrufbar) — bestehende Zuordnung trotzdem
										     sichtbar lassen, mit zwischengespeichertem Namen, statt sie optisch zu verlieren. -->
										<option value="<?php p($aktuelleZuordnung); ?>" selected>
											<?php p($_['zuordnungen'][$mitglied['uid']]['redmine_user_name'] ?? ('#' . $aktuelleZuordnung)); ?>
										</option>
									<?php endif; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if (count($_['redmineBenutzer']) === 0): ?>
				<p class="settings-hint" style="color: #c62828;">
					<?php p($l->t('Redmines Benutzerliste konnte gerade nicht abgerufen werden (verlangt auf vielen Redmine-Instanzen Administratorrechte für den hinterlegten API-Schlüssel) — bereits gespeicherte Zuordnungen bleiben erhalten, neue lassen sich aber erst wieder auswählen, sobald der Abruf klappt.')); ?>
				</p>
			<?php endif; ?>
			<br>
		<?php endif; ?>

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

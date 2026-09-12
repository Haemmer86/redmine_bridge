<?php

declare(strict_types=1);

namespace OCA\RedmineBruecke\Service;

use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

/**
 * Kapselt Nextclouds eigenes Schlagwort-System (SystemTags) für Dateien in
 * der Ticket-Ablage.
 *
 * Bewusst dasselbe System wie die normale Dateien-App, kein eigenes —
 * Schlagworte, die hier vergeben werden, erscheinen auch beim Durchsuchen
 * der Dateien in Nextcloud selbst, und umgekehrt.
 */
class TagService {

	/**
	 * Der Objekttyp, unter dem Nextclouds SystemTags Datei-Zuordnungen
	 * führt — derselbe, den auch die Dateien-App intern verwendet.
	 */
	private const OBJEKTTYP = 'files';

	public function __construct(
		private readonly ISystemTagManager $tagManager,
		private readonly ISystemTagObjectMapper $objectMapper,
	) {
	}

	/**
	 * Alle in Nextcloud vorhandenen Schlagworte, für Vorschläge in der
	 * Oberfläche.
	 *
	 * @return list<array{id:int,name:string}>
	 */
	public function alleTags(): array {
		$tags = $this->tagManager->getAllTags(true);

		return array_values(array_map($this->alsArray(...), $tags));
	}

	/**
	 * @return list<array{id:int,name:string}>
	 */
	public function tagsFuerDatei(int $dateiId): array {
		$zuordnung = $this->objectMapper->getTagIdsForObjects([(string)$dateiId], self::OBJEKTTYP);
		$tagIds = $zuordnung[(string)$dateiId] ?? [];
		if ($tagIds === []) {
			return [];
		}

		return array_values(array_map($this->alsArray(...), $this->tagManager->getTagsByIds($tagIds)));
	}

	/**
	 * Setzt ein Schlagwort per Namen — legt es an, falls es noch nicht
	 * existiert, und ordnet es der Datei zu (bestehende Schlagworte
	 * bleiben erhalten).
	 *
	 * @return list<array{id:int,name:string}> die Schlagworte der Datei danach
	 */
	public function tagSetzen(int $dateiId, string $name): array {
		$name = trim($name);
		if ($name === '') {
			throw new \RuntimeException('Schlagwort darf nicht leer sein.');
		}

		$tag = $this->findeOderErstelle($name);
		$this->objectMapper->assignTags((string)$dateiId, self::OBJEKTTYP, [$tag->getId()]);

		return $this->tagsFuerDatei($dateiId);
	}

	/**
	 * @return list<array{id:int,name:string}> die Schlagworte der Datei danach
	 */
	public function tagEntfernen(int $dateiId, int $tagId): array {
		$this->objectMapper->unassignTags((string)$dateiId, self::OBJEKTTYP, [(string)$tagId]);

		return $this->tagsFuerDatei($dateiId);
	}

	/**
	 * `createTag()` wirft bei einem bereits vorhandenen Namen — deshalb
	 * erst suchen, nur bei echtem Fehlen neu anlegen.
	 */
	private function findeOderErstelle(string $name): ISystemTag {
		foreach ($this->tagManager->getAllTags(true, $name) as $tag) {
			if ($tag->getName() === $name) {
				return $tag;
			}
		}

		return $this->tagManager->createTag($name, true, true);
	}

	/**
	 * @return array{id:int,name:string}
	 */
	private function alsArray(ISystemTag $tag): array {
		return ['id' => (int)$tag->getId(), 'name' => $tag->getName()];
	}
}

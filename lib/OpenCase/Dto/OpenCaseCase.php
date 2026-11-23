<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\OpenCase\Dto;

/**
 * Read-only view of an OpenCase case. OpenCase remains the authoritative owner.
 */
final class OpenCaseCase implements \JsonSerializable {
	public function __construct(
		public readonly string $id,
		public readonly string $caseNumber,
		public readonly string $title,
		public readonly ?string $description,
		public readonly string $status,
		public readonly ?string $responsibleUnit,
	) {
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'caseNumber' => $this->caseNumber,
			'title' => $this->title,
			'description' => $this->description,
			'status' => $this->status,
			'responsibleUnit' => $this->responsibleUnit,
		];
	}
}

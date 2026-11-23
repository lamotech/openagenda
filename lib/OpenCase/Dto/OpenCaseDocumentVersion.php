<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\OpenCase\Dto;

final class OpenCaseDocumentVersion implements \JsonSerializable {
	public function __construct(
		public readonly string $versionId,
		public readonly int $versionNumber,
		public readonly int $createdAt,
		public readonly ?string $checksum,
	) {
	}

	public function jsonSerialize(): array {
		return [
			'versionId' => $this->versionId,
			'versionNumber' => $this->versionNumber,
			'createdAt' => $this->createdAt,
			'checksum' => $this->checksum,
		];
	}
}

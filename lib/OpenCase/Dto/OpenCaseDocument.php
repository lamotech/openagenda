<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\OpenCase\Dto;

/**
 * Document metadata. Access level semantics are owned by OpenCase; OpenAgenda
 * only uses it to decide whether an attachment may appear in public output.
 */
final class OpenCaseDocument implements \JsonSerializable {
	public const ACCESS_PUBLIC = 'public';
	public const ACCESS_INTERNAL = 'internal';
	public const ACCESS_CONFIDENTIAL = 'confidential';

	public function __construct(
		public readonly string $id,
		public readonly string $caseId,
		public readonly string $title,
		public readonly string $documentType,
		public readonly string $accessLevel,
		public readonly ?OpenCaseDocumentVersion $latestVersion,
	) {
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'caseId' => $this->caseId,
			'title' => $this->title,
			'documentType' => $this->documentType,
			'accessLevel' => $this->accessLevel,
			'latestVersion' => $this->latestVersion,
		];
	}
}

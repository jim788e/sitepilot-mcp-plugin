<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

/**
 * Classifies Elementor element types without treating runtime discovery as
 * permission to construct undocumented Elementor internals.
 */
final class ElementorElementRegistry {

	/** Element types SitePilot can safely construct from public document data. */
	public const CONSTRUCTIBLE_TYPES = array( 'container', 'section', 'column', 'widget' );

	/** @var list<string>|null */
	private ?array $runtime_types;

	/** @param list<string>|null $runtime_types Test/runtime override. */
	public function __construct( ?array $runtime_types = null ) {
		$this->runtime_types = null === $runtime_types
			? null
			: array_values( array_unique( array_filter( array_map( 'sanitize_key', $runtime_types ) ) ) );
	}

	/** @return list<string> */
	public function runtime_types(): array {
		if ( null !== $this->runtime_types ) {
			return $this->runtime_types;
		}
		$types = array();
		try {
			if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->elements_manager ) ) {
				$registered = \Elementor\Plugin::$instance->elements_manager->get_element_types();
				if ( is_array( $registered ) ) {
					$types = array_keys( $registered );
				}
			}
		} catch ( \Throwable ) {
			$types = array();
		}
		$this->runtime_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
		return $this->runtime_types;
	}

	public function classification( string $type ): string {
		$type = sanitize_key( $type );
		if ( in_array( $type, self::CONSTRUCTIBLE_TYPES, true ) ) {
			return 'constructible';
		}
		if ( in_array( $type, $this->runtime_types(), true ) || str_starts_with( $type, 'e-' ) ) {
			return 'runtime_only';
		}
		return 'unknown';
	}

	public function is_constructible( string $type ): bool {
		return 'constructible' === $this->classification( $type );
	}

	/** @return array{editable:bool,editability:string,reason:?string} */
	public function editability( string $type ): array {
		return match ( $this->classification( $type ) ) {
			'constructible' => array(
				'editable'    => true,
				'editability' => 'full',
				'reason'      => null,
			),
			'runtime_only'  => array(
				'editable'    => true,
				'editability' => 'known_settings_only',
				'reason'      => 'runtime_only_not_constructible',
			),
			default         => array(
				'editable'    => false,
				'editability' => 'read_only',
				'reason'      => 'element_type_unmapped',
			),
		};
	}
}

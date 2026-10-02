<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM exposes camelCase properties.

/** Normalizes source wrappers before native Enfold compilation. */
final class EnfoldHtmlNormalizer {
	public function __construct( private EnfoldHtmlSource $source ) {}

	/** @return array<int,string> */
	public function remember_original_paths( \DOMXPath $xpath ): array {
		$paths = array();
		foreach ( EnfoldHtmlSource::elements( $xpath->query( '//body//*' ) ) as $element ) {
			$path                               = $this->source->source_path( $element );
			$paths[ spl_object_id( $element ) ] = $path;
			$element->setAttribute( 'data-sitepilot-original-path', $path );
		}
		return $paths;
	}

	public function normalize_roots( \DOMXPath $xpath ): void {
		$this->expand_page_wrapper_sections( $xpath );
		foreach ( EnfoldHtmlSource::elements( $xpath->query( '//body//main' ) ) as $main ) {
			$this->normalize_root_children( $main );
		}
	}

	public function normalize_nested_layout_groups(
		\DOMXPath $xpath,
		callable $is_layout_group,
		callable $is_card,
		callable $is_composite,
		callable $preserves_structure
	): void {
		$changed = true;
		while ( $changed ) {
			$changed = false;
			foreach ( EnfoldHtmlSource::elements( $xpath->query( '//body//section//*' ) ) as $group ) {
				if ( ! $is_layout_group( $group ) || $is_card( $group ) || $is_composite( $group ) || $preserves_structure( $group ) || $this->has_composite_ancestor( $group, $is_composite, $preserves_structure ) ) {
					continue;
				}
				$section = $group->parentNode;
				while ( $section instanceof \DOMElement && 'section' !== strtolower( $section->tagName ) ) {
					$section = $section->parentNode;
				}
				if ( ! $section instanceof \DOMElement || $group->parentNode === $section ) {
					continue;
				}
				$parent = $group->parentNode;
				if ( $parent instanceof \DOMElement && $is_layout_group( $parent ) && $parent->parentNode === $section ) {
					continue;
				}
				$anchor = $group;
				while ( $anchor->parentNode instanceof \DOMElement && $anchor->parentNode !== $section ) {
					$anchor = $anchor->parentNode;
				}
				$section->insertBefore( $group, $anchor->nextSibling );
				$changed = true;
				break;
			}
		}
	}

	private function expand_page_wrapper_sections( \DOMXPath $xpath ): void {
		foreach ( EnfoldHtmlSource::elements( $xpath->query( '//body//main' ) ) as $main ) {
			foreach ( EnfoldHtmlSource::direct_elements( $main ) as $wrapper ) {
				if ( 'section' !== strtolower( $wrapper->tagName ) ) {
					continue;
				}
				$sections = array_values( array_filter( EnfoldHtmlSource::direct_elements( $wrapper ), static fn ( \DOMElement $child ): bool => 'section' === strtolower( $child->tagName ) ) );
				if ( count( $sections ) < 2 || ! $wrapper->parentNode instanceof \DOMNode ) {
					continue;
				}
				$classes = preg_split( '/\s+/u', trim( $main->getAttribute( 'class' ) . ' ' . $wrapper->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
				$main->setAttribute( 'class', implode( ' ', array_unique( is_array( $classes ) ? $classes : array() ) ) );
				if ( '' === $main->getAttribute( 'id' ) && '' !== $wrapper->getAttribute( 'id' ) ) {
					$main->setAttribute( 'id', $wrapper->getAttribute( 'id' ) );
				}
				$parent = $wrapper->parentNode;
				foreach ( iterator_to_array( $wrapper->childNodes ) as $child ) {
					$parent->insertBefore( $child, $wrapper );
				}
				$parent->removeChild( $wrapper );
			}
		}
	}

	/** @return array{0:?\DOMElement,1:?\DOMElement} */
	private function normalize_root_children( \DOMElement $container ): array {
		$pending = array();
		$first   = null;
		$last    = null;
		foreach ( EnfoldHtmlSource::direct_elements( $container ) as $child ) {
			if ( 'section' === strtolower( $child->tagName ) ) {
				$this->attach_pending( $child, $pending );
				$pending = array();
				$first   = $first ?? $child;
				$last    = $child;
				continue;
			}
			if ( $this->first_direct_section( $child ) instanceof \DOMElement ) {
				list( $wrapped_first, $wrapped_last ) = $this->normalize_root_children( $child );
				if ( $wrapped_first instanceof \DOMElement ) {
					$this->attach_pending( $wrapped_first, $pending );
					$pending = array();
					$first   = $first ?? $wrapped_first;
				}
				$last = $wrapped_last ?? $last;
				continue;
			}
			$pending[] = $child;
		}
		if ( $last instanceof \DOMElement ) {
			foreach ( $pending as $element ) {
				$last->appendChild( $element );
			}
		}
		return array( $first, $last );
	}

	/** @param list<\DOMElement> $pending */
	private function attach_pending( \DOMElement $section, array $pending ): void {
		$first = $section->firstChild;
		foreach ( $pending as $element ) {
			$section->insertBefore( $element, $first );
		}
	}

	private function first_direct_section( \DOMElement $container ): ?\DOMElement {
		foreach ( EnfoldHtmlSource::direct_elements( $container ) as $child ) {
			if ( 'section' === strtolower( $child->tagName ) ) {
				return $child;
			}
		}
		return null;
	}

	private function has_composite_ancestor( \DOMElement $element, callable $is_composite, callable $preserves_structure ): bool {
		$parent = $element->parentNode;
		while ( $parent instanceof \DOMElement ) {
			if ( $is_composite( $parent ) || $preserves_structure( $parent ) ) {
				return true;
			}
			if ( 'section' === strtolower( $parent->tagName ) ) {
				break;
			}
			$parent = $parent->parentNode;
		}
		return false;
	}
}

<?php

namespace JDB;

use JDB\Exception\FileSystemException;
use JDB\Exception\NameAlreadyUsedException;

/**
 * Handles tables.
 */
class Table
{
	/**
	 * Table path without extension.
	 */
	public string $path;
	
	/**
	 * Table's meta file full path.
	 */
	public string $metaPath;
	
	/**
	 * Table's full path.
	 */
	public string $dir;

	/**
	 * Determines that table has changed or not.
	 */
	public $isDirty = false;

	/**
	 * Table's meta file interface.
	 */
	public Meta $meta;
	
	/**
	 * Collects all the rows in the table.
	 */
	public Collection $data;

	/**
	 * Table constructor.
	 */
	public function __construct(
		private Database $database,
		public string $name
	)
	{
		$this->dir = $database->path . $name;
		$this->path = $this->dir . '.' . JDB::DBEXT;
		$this->metaPath = $this->dir . '.' . JDB::METAEXT;

		$this->meta = new Meta( $this->metaPath );

		$this->loadAll();
	}

	/**
	 * It works like a proxy. When an undefined method called on this
	 * object, it's gonna try to find that method on this table's
	 * collection object.
	 */
	public function __call( string $name, array $args ): mixed
	{
		$collectionMethods = get_class_methods( $this->data );

		if( in_array( $name, $collectionMethods, true ))
		{
			return $this->data->{ $name }( ...$args );
		}
	}

	/**
	 * Loads all the rows.
	 */
	private function loadAll(): void
	{
		$this->data = new Collection(
			json_decode(
				file_get_contents( $this->path )
			)
		);

		$this->data = $this->data->map( fn( $item, $index ) =>
			new Row( $this, $this->data, $item, $index )
		);
	}

	/**
	 * Returns all rows.
	 */
	public function all(): Collection
	{
		return $this->data;
	}

	/**
	 * Inserts a new row to table.
	 */
	public function insert( array ...$data ): self
	{
		$this->isDirty = true;
	
		foreach( $data as $item )
		{
			$this->meta->current_id++;
			$this->meta->rows++;
			
			$item[ 'id' ] = $this->meta->current_id;

			$this->data->push(
				new Row( $this, $this->data, $item, $this->data->length )
			);
		}

		return $this;
	}

	/**
	 * Saves any changes in the table.
	 * 
	 * Returns null if the table is not saved because there
	 * is no change.
	 * 
	 * Returns true if there are changes and the table and
	 * metafile are saved successfully.
	 * 
	 * Returns false if failed to put changes into the file
	 * because of the file system related issues.
	 */
	public function save(): null|bool
	{
		if( ! $this->isDirty )
		{
			return null;
		}

		$put = file_put_contents(
			$this->path,
			json_encode( $this->data->toArray())
		);

		if( $put !== false )
		{
			$meta = $this->meta->save();
			return $meta === true || $meta === null;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Returns total size of the table.
	 */
	public function size(): int
	{
		clearstatcache( false, $this->path );
		return filesize( $this->path );
	}

	/**
	 * Returns table time infos.
	 */
	public function times(): array
	{
		return [
			'created' => filectime( $this->path ),
			'accessed' => fileatime( $this->path ),
			'modified' => filemtime( $this->path )
		];
	}

	/**
	 * Renames the table.
	 * 
	 * @throws NameAlreadyUsedException when the given name is already in use
	 * @throws FileSystemException when native rename method fails
	 */
	public function rename( string $newName ): bool
	{
		$newPath = $this->database->path . $newName . '.' . JDB::DBEXT;
		$newPathMeta = $this->database->path . $newName . '.' . JDB::METAEXT;

		if( file_exists( $newPath ))
		{
			throw new NameAlreadyUsedException(
				"Because of the $newName already in use, " .
				"{$this->database->name}.$this->name table can not renamed to $newName."
			);
		}

		if( ! ( @rename( $this->path, $newPath )))
		{
			throw new FileSystemException( 'Renaming the table failed!' );
		}
		
		@rename( $this->metaPath, $newPathMeta );

		return true;
	}

}

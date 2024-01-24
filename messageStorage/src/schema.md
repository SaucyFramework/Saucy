```php
Schema::create('message_storage', function (Blueprint $table) {
    $table->unsignedBigInteger('global_position')->primary();
    $table->ulid('message_id');
    $table->string('message_type');
    $table->string('stream_name');
    $table->unsignedInteger('stream_position');
    $table->json('json_payload');
    $table->json('json_metadata')->nullable();
    $table->dateTime('created_at');
});
```

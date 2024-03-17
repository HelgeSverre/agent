<?php

use App\TextUtils;

it('splits text into chunks without overlap', function () {
    $text = 'This is a test string used for splitting into chunks.';
    $chunkSize = 10;
    $expected = ['This is a ', 'test string', 'used for', 'splitting', ' into chunks.'];

    expect(TextUtils::splitText($text, $chunkSize))->toBe($expected);
});
//
//it('splits text into chunks with overlap', function () {
//    $text = "This is another test string.";
//    $chunkSize = 10;
//    $overlap = 5;
//    $expected = ["This is an", "is anothe", "another te", "her test ", "test strin", "string."];
//
//    expect(TextUtils::splitText($text, $chunkSize, $overlap))->toBe($expected);
//});

it('handles small text correctly', function () {
    $text = 'Short text';
    $chunkSize = 20;
    $expected = ['Short text'];

    expect(TextUtils::splitText($text, $chunkSize))->toBe($expected);
});

it('handles empty text correctly', function () {
    $text = '';
    $chunkSize = 10;
    $expected = [];

    expect(TextUtils::splitText($text, $chunkSize))->toBe($expected);
});

// Add more test cases as needed...

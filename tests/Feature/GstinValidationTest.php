<?php

use App\Enums\IndianState;
use App\Rules\ValidGstin;
use Illuminate\Support\Facades\Validator;

test('gstin is optional and accepts null or empty string', function () {
    $rule = new ValidGstin(IndianState::RAJASTHAN);

    $vNull = Validator::make(['gstin' => null], ['gstin' => [$rule]]);
    expect($vNull->passes())->toBeTrue();

    $vBlank = Validator::make(['gstin' => ''], ['gstin' => [$rule]]);
    expect($vBlank->passes())->toBeTrue();

    $vSpaces = Validator::make(['gstin' => '   '], ['gstin' => [$rule]]);
    expect($vSpaces->passes())->toBeTrue();
});

test('valid 15-character gstin matching state code is accepted', function () {
    $rule = new ValidGstin(IndianState::RAJASTHAN);

    $validator = Validator::make(['gstin' => '08ABCDE1234F1Z5'], ['gstin' => [$rule]]);
    expect($validator->passes())->toBeTrue();

    // Check with Maharashtra (27)
    $ruleMh = new ValidGstin(IndianState::MAHARASHTRA);
    $validatorMh = Validator::make(['gstin' => '27ABCDE1234F1Z5'], ['gstin' => [$ruleMh]]);
    expect($validatorMh->passes())->toBeTrue();
});

test('malformed gstin is rejected', function () {
    $rule = new ValidGstin(IndianState::RAJASTHAN);

    // Too short (14 chars)
    $v1 = Validator::make(['gstin' => '08ABCDE1234F1Z'], ['gstin' => [$rule]]);
    expect($v1->fails())->toBeTrue();

    // Too long (16 chars)
    $v2 = Validator::make(['gstin' => '08ABCDE1234F1Z55'], ['gstin' => [$rule]]);
    expect($v2->fails())->toBeTrue();

    // Invalid characters
    $v3 = Validator::make(['gstin' => '08ABCDE1234F1@5'], ['gstin' => [$rule]]);
    expect($v3->fails())->toBeTrue();

    // Wrong 14th character (must be 'Z')
    $v4 = Validator::make(['gstin' => '08ABCDE1234F1A5'], ['gstin' => [$rule]]);
    expect($v4->fails())->toBeTrue();
});

test('gstin state code mismatch is rejected', function () {
    // Rajasthan is code 08, but GSTIN starts with Maharashtra 27
    $rule = new ValidGstin(IndianState::RAJASTHAN);
    $validator = Validator::make(['gstin' => '27ABCDE1234F1Z5'], ['gstin' => [$rule]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('gstin'))->toContain('do not match the selected state code');
});

test('gstin validation rule resolves state dynamically from form data', function () {
    $rule = new ValidGstin; // resolves from data['state']

    $validData = [
        'state' => '08',
        'gstin' => '08ABCDE1234F1Z5',
    ];
    $vPass = Validator::make($validData, ['gstin' => [$rule]]);
    expect($vPass->passes())->toBeTrue();

    $mismatchData = [
        'state' => '08',
        'gstin' => '27ABCDE1234F1Z5',
    ];
    $vFail = Validator::make($mismatchData, ['gstin' => [$rule]]);
    expect($vFail->fails())->toBeTrue();
});

test('check static helper method returns array of error messages', function () {
    expect(ValidGstin::check(null, '08'))->toBeEmpty();
    expect(ValidGstin::check('', '08'))->toBeEmpty();
    expect(ValidGstin::check('08ABCDE1234F1Z5', '08'))->toBeEmpty();
    expect(ValidGstin::check('08ABCDE1234F1Z5', IndianState::RAJASTHAN))->toBeEmpty();

    $formatErrors = ValidGstin::check('INVALID', '08');
    expect($formatErrors)->not->toBeEmpty()
        ->and($formatErrors[0])->toContain('Expected 15-character alphanumeric');

    $mismatchErrors = ValidGstin::check('27ABCDE1234F1Z5', '08');
    expect($mismatchErrors)->not->toBeEmpty()
        ->and($mismatchErrors[0])->toContain('does not match');
});

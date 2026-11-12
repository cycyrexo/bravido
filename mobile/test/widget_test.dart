import 'package:bravido/core/outils.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('formatDistance', () {
    expect(formatDistance(null), '');
    expect(formatDistance(350.4), '350 m');
    expect(formatDistance(1520), '1,5 km');
  });

  test('formatDate', () {
    expect(formatDate('2026-11-10'), '10/11/2026');
    expect(formatDate(null), '');
  });
}

<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Grades a quiz submission.
 *
 * Marking happens entirely from the stored questions. The submission carries
 * only which option was chosen, so a client cannot claim its own score, and
 * the answer key never has to travel to the browser to be compared.
 */
final class QuizGrader
{
    /**
     * @param list<array<string, mixed>> $questions  Rows from quiz_questions.
     * @param array<string, int>         $selections Question id mapped to the chosen option index.
     * @return array{score_percent: int, points_earned: int, points_possible: int, correct_count: int, answers: list<array<string, mixed>>}
     */
    public function grade(array $questions, array $selections): array
    {
        $possible = 0;
        $earned = 0;
        $correctCount = 0;
        $answers = [];

        foreach ($questions as $question) {
            $questionId = (string) $question['id'];
            $points = (int) $question['points'];
            $possible += $points;

            $selected = array_key_exists($questionId, $selections) ? $selections[$questionId] : null;
            $isCorrect = $selected !== null && $selected === (int) $question['correct_index'];

            if ($isCorrect) {
                $earned += $points;
                $correctCount++;
            }

            $answers[] = [
                'question_id' => $questionId,
                'selected_index' => $selected,
                'correct' => $isCorrect,
                'points' => $isCorrect ? $points : 0,
            ];
        }

        return [
            'score_percent' => $possible === 0 ? 0 : (int) round(($earned / $possible) * 100),
            'points_earned' => $earned,
            'points_possible' => $possible,
            'correct_count' => $correctCount,
            'answers' => $answers,
        ];
    }

    /**
     * The quiz as a learner may see it, with the answer key removed.
     *
     * Stripping happens server side on purpose. Sending correct_index and
     * hiding it in the interface would put the whole answer key one network
     * inspection away.
     *
     * @param list<array<string, mixed>> $questions
     * @return list<array<string, mixed>>
     */
    public function withoutAnswerKey(array $questions): array
    {
        return array_map(
            static fn (array $question): array => [
                'id' => $question['id'],
                'question' => $question['question'],
                'options' => array_values((array) $question['options']),
                'points' => (int) $question['points'],
                'sequence' => (int) $question['sequence'],
            ],
            $questions
        );
    }

    /**
     * The post-attempt review, including why each answer was right or wrong.
     *
     * @param list<array<string, mixed>> $questions
     * @param list<array<string, mixed>> $answers Graded answers produced by grade().
     * @return list<array<string, mixed>>
     */
    public function review(array $questions, array $answers): array
    {
        $byQuestion = [];
        foreach ($answers as $answer) {
            $byQuestion[(string) $answer['question_id']] = $answer;
        }

        $review = [];

        foreach ($questions as $question) {
            $questionId = (string) $question['id'];
            $answer = $byQuestion[$questionId] ?? null;

            $review[] = [
                'id' => $questionId,
                'question' => $question['question'],
                'options' => array_values((array) $question['options']),
                'selected_index' => $answer['selected_index'] ?? null,
                'correct_index' => (int) $question['correct_index'],
                'correct' => (bool) ($answer['correct'] ?? false),
                'explanation' => $question['explanation'] ?? null,
            ];
        }

        return $review;
    }
}

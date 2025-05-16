/**
 * TrueFire API Service
 * 
 * Provides methods to fetch TrueFire courses and lessons data
 * Uses mock data for development (will be replaced with actual API endpoints)
 */

import axios from 'axios';

// Mock data for development
const mockCourses = [
  {
    id: 1,
    title: "Guitar Course 1",
    description: "This is a sample description for the guitar course 1. It covers various techniques and styles for guitar players of all levels.",
    instructor: "Guitar Instructor A",
    difficulty_level: "Intermediate",
    category: "Blues",
    published_at: "2023-10-15",
    duration_minutes: 180,
    thumbnail_url: "https://placehold.co/600x400?text=Course+1",
    lessons: getMockLessons(1)
  },
  {
    id: 2,
    title: "Guitar Course 2",
    description: "This is a sample description for the guitar course 2. It covers various techniques and styles for guitar players of all levels.",
    instructor: "Guitar Instructor B",
    difficulty_level: "Beginner",
    category: "Rock",
    published_at: "2023-11-20",
    duration_minutes: 120,
    thumbnail_url: "https://placehold.co/600x400?text=Course+2",
    lessons: getMockLessons(2)
  },
  {
    id: 3,
    title: "Guitar Course 3",
    description: "This is a sample description for the guitar course 3. It covers various techniques and styles for guitar players of all levels.",
    instructor: "Guitar Instructor C",
    difficulty_level: "Advanced",
    category: "Jazz",
    published_at: "2023-12-05",
    duration_minutes: 240,
    thumbnail_url: "https://placehold.co/600x400?text=Course+3",
    lessons: getMockLessons(3)
  },
  {
    id: 4,
    title: "Guitar Course 4",
    description: "This is a sample description for the guitar course 4. It covers various techniques and styles for guitar players of all levels.",
    instructor: "Guitar Instructor D",
    difficulty_level: "All Levels",
    category: "Country",
    published_at: "2024-01-10",
    duration_minutes: 160,
    thumbnail_url: "https://placehold.co/600x400?text=Course+4",
    lessons: getMockLessons(4)
  },
  {
    id: 5,
    title: "Guitar Course 5",
    description: "This is a sample description for the guitar course 5. It covers various techniques and styles for guitar players of all levels.",
    instructor: "Guitar Instructor E",
    difficulty_level: "Intermediate",
    category: "Classical",
    published_at: "2024-02-15",
    duration_minutes: 200,
    thumbnail_url: "https://placehold.co/600x400?text=Course+5",
    lessons: getMockLessons(5)
  }
];

// Helper function to generate mock lessons
function getMockLessons(courseId) {
  const lessonTitles = [
    'Basic Chord Progressions',
    'Fingerpicking Techniques',
    'Understanding Scales',
    'Guitar Solo Fundamentals',
    'Rhythm and Timing',
    'Barre Chords Masterclass',
    'Blues Improvisation',
    'Jazz Chord Voicings',
    'Alternate Picking',
    'Advanced Hammer-ons & Pull-offs'
  ];
  
  const count = Math.floor(Math.random() * 7) + 4; // 4-10 lessons
  const lessons = [];
  
  for (let i = 1; i <= count; i++) {
    const randomTitle = lessonTitles[Math.floor(Math.random() * lessonTitles.length)];
    lessons.push({
      id: (courseId * 100) + i,
      course_id: courseId,
      title: `Lesson ${i}: ${randomTitle}`,
      description: "This lesson covers important techniques and concepts for guitar players. You'll learn step-by-step how to master these skills.",
      sequence_number: i,
      duration_minutes: Math.floor(Math.random() * 41) + 5, // 5-45 minutes
      video_url: `https://example.com/videos/course-${courseId}/lesson-${i}.mp4`,
      thumbnail_url: `https://placehold.co/400x225?text=Lesson+${i}`,
      is_free_preview: i === 1 // First lesson is free
    });
  }
  
  return lessons;
}

// Create API instance with config
const apiClient = axios.create({
  baseURL: 'https://api.truefire.com', // Will be replaced with actual API endpoint
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
});

// API Service
const trueFireApi = {
  /**
   * Get all TrueFire courses
   * @returns {Promise} Promise with courses data
   */
  getCourses() {
    // For development, return mock data
    return new Promise((resolve) => {
      setTimeout(() => {
        resolve({ data: mockCourses });
      }, 300); // Simulate network delay
    });
    
    // For production: 
    // return apiClient.get('/courses');
  },

  /**
   * Get a specific TrueFire course by ID
   * @param {number} id Course ID
   * @returns {Promise} Promise with course data
   */
  getCourse(id) {
    // For development, return mock data
    return new Promise((resolve, reject) => {
      setTimeout(() => {
        const course = mockCourses.find(c => c.id === parseInt(id));
        if (course) {
          resolve({ data: course });
        } else {
          reject(new Error('Course not found'));
        }
      }, 300); // Simulate network delay
    });
    
    // For production:
    // return apiClient.get(`/courses/${id}`);
  },

  /**
   * Import a lesson from TrueFire to the transcription system
   * @param {number} lessonId Lesson ID to import
   * @returns {Promise} Promise with import result
   */
  importLesson(lessonId) {
    // For development
    return new Promise((resolve) => {
      setTimeout(() => {
        resolve({ data: { success: true, message: 'Lesson imported successfully' } });
      }, 500);
    });
    
    // For production:
    // return apiClient.post(`/import/${lessonId}`);
  },

  /**
   * Import multiple lessons in bulk
   * @param {Array} lessonIds Array of lesson IDs to import
   * @returns {Promise} Promise with import result
   */
  importLessonsBulk(lessonIds) {
    // For development
    return new Promise((resolve) => {
      setTimeout(() => {
        resolve({ 
          data: { 
            success: true, 
            count: lessonIds.length,
            message: `${lessonIds.length} lessons imported successfully` 
          } 
        });
      }, 800);
    });
    
    // For production:
    // return apiClient.post('/import-bulk', { lessons: lessonIds });
  }
};

export default trueFireApi; 
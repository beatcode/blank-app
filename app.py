import streamlit as st
from pydub import AudioSegment
import simpleaudio as sa
import os

# Ordner mit Einzeltönen (z. B. "C4.wav", "D#4.wav", ...)
SAMPLES_PATH = "samples/"

# Hilfsfunktion zum Laden und Abspielen eines Akkords
def play_chord(notes):
    combined = AudioSegment.silent(duration=1000)
    for note in notes:
        sound = AudioSegment.from_wav(os.path.join(SAMPLES_PATH, f"{note}.wav"))
        combined = combined.overlay(sound)
    # Exportiere temporär
    combined.export("temp.wav", format="wav")
    wave_obj = sa.WaveObject.from_wave_file("temp.wav")
    play_obj = wave_obj.play()
    play_obj.wait_done()

# Definition einiger Akkorde
chords = {
    "Bbm": ["Bb3", "Db4", "F4"],
    "Cdim": ["C4", "Eb4", "Gb4"],
    "Db": ["Db4", "F4", "Ab4"],
    "Ebm": ["Eb3", "Gb3", "Bb3"],
    "Fm": ["F3", "Ab3", "C4"],
    "Gb": ["Gb3", "Bb3", "Db4"],
    "Ab": ["Ab3", "C4", "Eb4"],
}

# Streamlit UI
st.title("Akkorde auf dem Piano hören")
st.write("Klicke auf einen Akkord, um ihn zu hören.")

cols = st.columns(3)
i = 0
for chord_name, notes in chords.items():
    if cols[i].button(chord_name):
        play_chord(notes)
    i = (i + 1) % 3
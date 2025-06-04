# Simple Windows-compatible TODO App with STT using Tkinter
import tkinter as tk
from tkinter import messagebox, simpledialog
import speech_recognition as sr
import threading

class TodoApp:
    def __init__(self, root):
        self.root = root
        self.root.title("Speech To-Do App")
        self.tasks = []

        self.frame = tk.Frame(root)
        self.frame.pack(padx=10, pady=10)

        self.listbox = tk.Listbox(self.frame, width=50)
        self.listbox.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)

        scrollbar = tk.Scrollbar(self.frame, orient=tk.VERTICAL)
        scrollbar.config(command=self.listbox.yview)
        scrollbar.pack(side=tk.RIGHT, fill=tk.Y)
        self.listbox.config(yscrollcommand=scrollbar.set)

        btn_frame = tk.Frame(root)
        btn_frame.pack(pady=5)

        tk.Button(btn_frame, text="Add", command=self.add_task).pack(side=tk.LEFT, padx=5)
        tk.Button(btn_frame, text="Edit", command=self.edit_task).pack(side=tk.LEFT, padx=5)
        tk.Button(btn_frame, text="Delete", command=self.delete_task).pack(side=tk.LEFT, padx=5)
        tk.Button(btn_frame, text="Voice Add", command=self.voice_add).pack(side=tk.LEFT, padx=5)

    def add_task(self, text=None):
        if text is None:
            text = simpledialog.askstring("Add Task", "Task description:")
        if text:
            self.tasks.append(text)
            self.refresh()

    def edit_task(self):
        sel = self.listbox.curselection()
        if sel:
            index = sel[0]
            new_text = simpledialog.askstring("Edit Task", "Task description:", initialvalue=self.tasks[index])
            if new_text:
                self.tasks[index] = new_text
                self.refresh()

    def delete_task(self):
        sel = self.listbox.curselection()
        if sel:
            index = sel[0]
            del self.tasks[index]
            self.refresh()

    def refresh(self):
        self.listbox.delete(0, tk.END)
        for t in self.tasks:
            self.listbox.insert(tk.END, t)

    def voice_add(self):
        threading.Thread(target=self._voice_thread).start()

    def _voice_thread(self):
        recognizer = sr.Recognizer()
        with sr.Microphone() as source:
            try:
                audio = recognizer.listen(source, timeout=5)
                text = recognizer.recognize_google(audio, language="de-DE")
                self.root.after(0, lambda: self.add_task(text))
            except Exception as e:
                self.root.after(0, lambda: messagebox.showerror("Error", str(e)))

if __name__ == "__main__":
    root = tk.Tk()
    app = TodoApp(root)
    root.mainloop()
